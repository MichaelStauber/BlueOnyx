"""
agent.py -- Tool-calling agent for BlueOnyx AI service.

Copyright (c) 2008-2026 Michael Stauber, SOLARSPEED.NET
Copyright (c) 2008-2026 Team BlueOnyx, BLUEONYX.IT
All Rights Reserved.

Implements the agent loop: receive message -> LLM -> tool execution ->
repeat until final answer.
"""

from __future__ import annotations

import asyncio
import json
import logging
import os
import re
import time
import shlex
from typing import Any, AsyncGenerator

from ..providers.external_provider import ExternalProvider
from ..tools.base import ToolExecutor, ToolResult
from ..tools.knowledge_tools import build_knowledge_brief
from .session import SessionManager

logger = logging.getLogger("sausalito_ai.agent.agent")

BLUEONYX_KNOWLEDGE_BRIEF = build_knowledge_brief()

MODEL_PROFILE_GUIDANCE = {
    "restricted": (
        "Model capability profile: restricted.\n"
        "Use tools conservatively and prefer deterministic BlueOnyx facts over freeform reasoning.\n"
        "For technical investigations, keep the workflow short and do not improvise commands or procedures.\n"
        "If the question needs deeper analysis than the available evidence supports, say so plainly."
    ),
    "guided": (
        "Model capability profile: guided.\n"
        "Use tools to verify facts, keep answers structured, and avoid speculation.\n"
        "You may reason across multiple steps, but stay concise and evidence-based."
    ),
    "investigative": (
        "Model capability profile: investigative.\n"
        "You may choose tools more autonomously, run multi-step investigations, and compare evidence before answering.\n"
        "Summarize findings clearly, include uncertainty when needed, and do not invent facts."
    ),
    "freeform": (
        "Model capability profile: freeform.\n"
        "You have broad latitude to combine tools and reasoning steps.\n"
        "Stay factual, use evidence from tools, and keep unsafe or destructive actions confirmation-gated."
    ),
}

MODEL_PROFILE_MAX_ITERATIONS = {
    "restricted": 4,
    "guided": 8,
    "investigative": 12,
    "freeform": 12,
}

# ---------------------------------------------------------------------------
# Profile-based tool policy: which tool categories each profile may access.
# restricted: only read_only + diagnostics (safe, deterministic shortcuts)
# guided: + actions (service restart etc., with confirmation)
# investigative: + advanced (privileged wrapper commands)
# freeform: same as investigative but with broader reasoning latitude
# ---------------------------------------------------------------------------
PROFILE_TOOL_CATEGORIES: dict[str, set[str]] = {
    "restricted": {"read_only", "diagnostics"},
    "guided": {"read_only", "diagnostics", "actions"},
    "investigative": {"read_only", "diagnostics", "actions", "advanced"},
    "freeform": {"read_only", "diagnostics", "actions", "advanced"},
}

# For restricted profiles, further limit to a specific tool whitelist.
# These are the tools that deterministic shortcuts already use, plus
# the knowledge base lookup.  The model cannot pick tools freely --
# the shortcuts drive tool selection.
PROFILE_TOOL_WHITELIST: dict[str, set[str] | None] = {
    "restricted": {
        # Read-only log tools (used by shortcuts)
        "search_admin_logs",
        "search_logs",
        "incident_timeline",
        "mail_stats",
        "mail_health",
        "spam_abuse",
        "ssl_health",
        "php_fpm_health",
        "web_owner_health",
        "site_health_evidence",
        "list_vsites",
        "server_health_summary",
        # Diagnostics (used by shortcuts)
        "system_uname",
        "system_disk_space",
        "system_network_status",
        "system_network_interfaces",
        "system_network_counters",
        "system_network_routes",
        "system_network_sockets",
        "system_network_bandwidth",
        "system_network_dns",
        "service_status",
        "journalctl_query",
        # Knowledge base
        "search_blueonyx_knowledge",
    },
    "guided": None,        # No whitelist -- category filter is sufficient
    "investigative": None, # No whitelist -- category filter is sufficient
    "freeform": None,      # No whitelist -- category filter is sufficient
}

# ---------------------------------------------------------------------------
# Profile-dependent system prompts.
# restricted: ultra-compact -- small models have limited context windows.
# guided: standard length -- structured but complete.
# investigative/freeform: full prompt with knowledge brief.
# ---------------------------------------------------------------------------
PROFILE_SYSTEM_PROMPTS: dict[str, str | None] = {
    "restricted": (
        "You are BlueOnyx AI, a server administration assistant.\n"
        "Answer concisely. Use tools when available. Do NOT invent commands or facts.\n"
        "If unsure, say so and point the user to BlueOnyx support channels.\n"
        "For write operations, always ask for confirmation first.\n"
        "Prefer tool results over your own reasoning. Report tool output directly when asked for exact output."
    ),
    "guided": None,        # Falls back to BASE_SYSTEM_PROMPT
    "investigative": None, # Falls back to BASE_SYSTEM_PROMPT
    "freeform": None,      # Falls back to BASE_SYSTEM_PROMPT
}


# ---------------------------------------------------------------------------
# Profile-dependent knowledge brief limits.
# restricted: only top-3 entries, max 400 chars (tiny context window)
# guided: top-6 entries, max 1200 chars
# investigative/freeform: full brief (up to build_knowledge_brief defaults)
# ---------------------------------------------------------------------------
PROFILE_KNOWLEDGE_LIMITS: dict[str, dict[str, int]] = {
    "restricted": {"max_entries": 3, "max_chars": 400},
    "guided": {"max_entries": 6, "max_chars": 1200},
    "investigative": {"max_entries": 8, "max_chars": 1800},
    "freeform": {"max_entries": 8, "max_chars": 1800},
}

BASE_SYSTEM_PROMPT = """You are BlueOnyx AI, a server administration assistant for the BlueOnyx web hosting platform.

You help administrators manage and troubleshoot their servers. You have access to tools that let you:
- Read system logs
- Search log files for patterns
- Search across the common admin logs for auth, mail, cron, SSH, and service issues
- Summarize mail volume and delivery statistics with the dedicated `mail_stats` tool
- Summarize mail health, delivery trends, and filtering health with the dedicated `mail_health` tool
- Investigate spam abuse: identify which account is sending spam, suspicious sender volumes, and top offender IPs with the dedicated `spam_abuse` tool
- Inspect directories, file metadata, and file hashes
- Query the systemd journal
- Get system information (disk, memory, uptime, load)
- Show free disk space and filesystem usage with the dedicated `system_disk_space` tool
- Show a concise disk health summary for the fullest mounts and low-space warnings, including "disk space summary" style requests
- Inspect live network interfaces, counters, routes, sockets, DNS state, and historical bandwidth usage with the dedicated network tools
- Show per-user and per-vsite live disk usage by resolving the account and reading quota data from the live quota tools
- Check service status
- Query CCE (Configuration Engine) objects
- Return the exact output of `uname -a` via the dedicated `system_uname` tool when requested
- Run approved wrapper commands for exact command output when needed (for example, `uname -a`)
- Search the local BlueOnyx knowledge base for anchored BlueOnyx support answers
- Build incident timelines from the journal and common admin logs when the user asks what changed before a failure
- Check SSL certificate health for AdmServ and Vsites, including expiry, missing files, and obvious chain problems
- Check PHP-FPM health and only flag extra pools when a Vsite actually needs them
- Check Vsite /web ownership health and recommend a per-site GUI fix when only a small number of sites are wrong, or `set_web_owner.pl` when ownership drift is systemic
- Check site-level health evidence by combining web ownership, SSL, PHP-FPM, central logs, and quota evidence for a single Vsite
- Suggest narrow BlueOnyx helper scripts when they directly match the problem instead of inventing generic Linux advice

When asked to perform a task:
1. Use available tools to gather information
2. Provide clear, concise explanations
3. For write operations (changing configs, restarting services), you must ask for confirmation first
4. Always respect the principle of least privilege
5. If the user asks for the output of a command, do not paraphrase or summarize from memory; use an approved tool and return the exact output when possible. For `uname -a`, call `system_uname` directly.
6. For log investigations about failed logins, authentication failures, mail delivery problems, SSH failures, or similar admin incidents, prefer `search_admin_logs` first.
7. Distinguish failed logins from login attempts. A preauth disconnect or "authenticating user" line is an auth attempt, not a confirmed failed login.
8. For Vsite-creation questions, answer from the GUI and capability model: Vsites are created in Site Management via the `+ Add` button. If the button is missing or creation is denied, check `manageSite`, `maxVsite`, and related allocation limits. Do not invent `/etc/system` settings or fictional toggles.

9. For BlueOnyx-specific support questions that are not covered by the explicit shortcuts, consult the local BlueOnyx knowledge base first via `search_blueonyx_knowledge` instead of guessing.
10. If the local knowledge base and deterministic tools are not enough, prefer trusted BlueOnyx sources in this order: official website, official wiki, mailing list archive.
11. Treat Michael Stauber's emails as the highest-trust human source unless newer official docs or code contradict them.
12. If the answer is still uncertain after local knowledge, tools, and trusted sources, say so plainly and point the user to the BlueOnyx website, wiki, mailing list archive, Discord, or `Software Updates > Support > Support Request` for a support ticket. Do not invent a chat support function or similar GUI element.

Keep responses concise and technically accurate. When you're done, provide a summary of what was found or done."""

def _build_profile_prompt(profile: str, admin_prompt: str = "") -> str:
    """Build the full system prompt for a given capability profile.
    
    Applies:
    1. Profile-specific base prompt (compact for restricted, full for others)
    2. Profile-specific knowledge brief (truncated for restricted)
    3. Profile guidance paragraph
    4. Optional admin custom prompt
    """
    # 1. Profile-specific base prompt
    profile_base = PROFILE_SYSTEM_PROMPTS.get(profile)
    base = profile_base if profile_base is not None else BASE_SYSTEM_PROMPT
    
    # 2. Knowledge brief sized by profile
    limits = PROFILE_KNOWLEDGE_LIMITS.get(profile, PROFILE_KNOWLEDGE_LIMITS["guided"])
    profile_brief = build_knowledge_brief(
        max_entries=limits["max_entries"],
        max_chars=limits["max_chars"],
    )
    if profile_brief:
        base = base + "\n\n" + profile_brief
    
    # 3. Profile guidance
    prompt = base + "\n\n" + MODEL_PROFILE_GUIDANCE.get(profile, MODEL_PROFILE_GUIDANCE["guided"])
    
    # 4. Admin custom prompt
    admin = (admin_prompt or "").strip()
    if admin:
        prompt = prompt + "\n\n" + admin
    
    return prompt

# Tools that modify state -- require user confirmation
WRITE_TOOLS = {
    "cce_set",
    "run_privileged_command",
    "service_action",
    "restart_service",
    "stop_service",
    "start_service",
    "reload_service",
    "write_file",
    "edit_file",
    "execute_command",
}


class Agent:
    """Tool-calling agent with confirmation-gated write operations.

    The agent loop:
    1. Load conversation history from session manager
    2. Send messages + tool definitions to LLM
    3. If LLM returns text delta -> yield 'delta' event
    4. If LLM returns tool_call:
       - If read tool: execute immediately, yield 'tool_call' + 'tool_result'
       - If write tool: yield 'confirmation_required', pause, wait for /confirm
    5. Loop until LLM returns final answer
    6. Yield 'done' event
    """

    def __init__(
        self,
        provider: ExternalProvider,
        session_manager: SessionManager,
        tool_executor: ToolExecutor,
        system_prompt: str = "",
        model_profile: str = "guided",
        tools_enabled: bool = True,
        allowed_tool_categories: list[str] | None = None,
        allow_generic_privileged_command: bool = False,
    ) -> None:
        self.provider = provider
        self.session_manager = session_manager
        self.tool_executor = tool_executor
        self.model_profile = self._normalize_model_profile(model_profile)
        self.max_iterations = MODEL_PROFILE_MAX_ITERATIONS.get(self.model_profile, 8)

        # Merge profile-based tool categories with any config-provided categories.
        # Profile categories are the baseline; config can further restrict (but not expand).
        profile_categories = PROFILE_TOOL_CATEGORIES.get(self.model_profile, {"read_only", "diagnostics"})
        if allowed_tool_categories:
            # Config explicitly restricts: intersect with profile ceiling
            config_set = {str(item).strip() for item in allowed_tool_categories if str(item).strip()}
            merged = profile_categories & config_set
            self.allowed_tool_categories = sorted(merged)
        else:
            # No config override: use profile categories directly
            self.allowed_tool_categories = sorted(profile_categories)

        # For restricted profiles, also apply the tool whitelist
        self._tool_whitelist = PROFILE_TOOL_WHITELIST.get(self.model_profile)

        self.system_prompt = self._compose_system_prompt(system_prompt)
        self.tools_enabled = bool(tools_enabled)
        self.allow_generic_privileged_command = bool(allow_generic_privileged_command)
        self._last_user_message: str = ""
        logger.info(
            "Agent initialized: profile=%s categories=%s whitelist=%s iterations=%d",
            self.model_profile,
            self.allowed_tool_categories,
            "restricted-set" if self._tool_whitelist else "none",
            self.max_iterations,
        )

    def _normalize_model_profile(self, model_profile: str) -> str:
        profile = str(model_profile or "guided").strip().lower()
        if profile not in MODEL_PROFILE_GUIDANCE:
            return "guided"
        return profile

    def _get_model_profile_prompt(self) -> str:
        return MODEL_PROFILE_GUIDANCE.get(self.model_profile, MODEL_PROFILE_GUIDANCE["guided"])

    def _compose_system_prompt(self, system_prompt: str) -> str:
        admin_prompt = (system_prompt or "").strip()
        # Build the full prompt with profile-appropriate sizing
        return _build_profile_prompt(self.model_profile, admin_prompt)

    def _tool_context(self) -> dict[str, Any]:
        return {"model_profile": self.model_profile}

    async def _execute_tool(self, tool_name: str, tool_args: dict[str, Any], run_as: str = "blueonyx_ai") -> ToolResult:
        return await self.tool_executor.execute(tool_name, tool_args, run_as, context=self._tool_context())

    def _is_uname_request(self, message: str) -> bool:
        """
        Detect exact uname output requests and short-circuit them to the dedicated tool.

        This prevents smaller models from paraphrasing or hallucinating a command output
        when the user explicitly asks for the result of `uname -a`.
        """
        normalized = " ".join((message or "").strip().lower().split())
        if not normalized:
            return False
        patterns = (
            r"\buname\s+-a\b",
            r'["`]?uname\s+-a["`]?',
            r"\boutput of (the )?command\b.*\buname\s+-a\b",
            r"\bshow me\b.*\buname\s+-a\b",
            r"\bgive me\b.*\buname\s+-a\b",
            r"\bwhat is\b.*\buname\s+-a\b",
        )
        return any(re.search(pattern, normalized) for pattern in patterns)

    def _is_disk_space_request(self, message: str) -> bool:
        normalized = " ".join((message or "").strip().lower().split())
        if not normalized:
            return False
        patterns = (
            r"\b(free\s+disk\s+space|disk\s+space|disk\s+usage|filesystem\s+usage|filesystem|free\s+space|space\s+left)\b",
            r"\b(how\s+much|how\s+many)\b.*\b(disk|filesystem|space)\b",
            r"\b(df\s+-h|df\b)\b",
            r"\bhow\s+full\b.*\b(disk|filesystem)\b",
            r"\bhow\s+much\b.*\bspace\b",
        )
        return any(re.search(pattern, normalized) for pattern in patterns)

    def _is_disk_health_request(self, message: str) -> bool:
        normalized = " ".join((message or "").strip().lower().split())
        if not normalized:
            return False
        patterns = (
            r"\bdisk\s+health\b",
            r"\bfilesystem\s+health\b",
            r"\bhealth\s+of\s+the\s+disk\b",
            r"\bdisk\s+space\s+summary\b",
            r"\bstorage\s+summary\b",
            r"\bdisk\s+usage\s+summary\b",
            r"\bfilesystem\s+summary\b",
            r"\bmount\s+summary\b",
            r"\bdisk\s+capacity\s+report\b",
            r"\b(nearly\s+full|almost\s+full|most\s+full|fullest)\b.*\b(filesystem|mount|partition|disk)\b",
            r"\b(disk|filesystem|partition|mount)\b.*\b(health|status|summary|report)\b",
        )
        return any(re.search(pattern, normalized) for pattern in patterns)

    def _is_network_usage_request(self, message: str) -> bool:
        normalized = " ".join((message or "").strip().lower().split())
        if not normalized:
            return False
        patterns = (
            r"\b(network\s+usage|bandwidth|traffic|throughput|data\s+usage|live\s+traffic)\b",
            r"\b(network\s+stats|network\s+status|interface\s+usage|interface\s+traffic|socket\s+summary)\b",
            r"\b(vnstat|ip\s+-s\s+link|ss\s+-s|/proc/net/dev)\b",
            r"\b(which|what)\b.*\b(interface|nic|link)\b.*\b(busy|usage|traffic|throughput)\b",
            r"\b(network|internet)\b.*\b(usage|traffic|bandwidth|load|stat(?:s|istics)?)\b",
        )
        return any(re.search(pattern, normalized) for pattern in patterns)

    def _is_incident_timeline_request(self, message: str) -> bool:
        normalized = " ".join((message or "").strip().lower().split())
        if not normalized:
            return False
        patterns = (
            r"\bincident\s+timeline\b",
            r"\btimeline\b",
            r"\bwhat\s+changed\b.*\b(before|prior\s+to)\b",
            r"\bwhat\s+happened\b.*\bbefore\b",
            r"\bright\s+before\b",
            r"\bcorrelat(?:e|ion)\b.*\blogs\b",
            r"\bjournalctl\b.*\b(log|timeline)\b",
        )
        return any(re.search(pattern, normalized) for pattern in patterns)

    def _is_ssl_health_request(self, message: str) -> bool:
        normalized = " ".join((message or "").strip().lower().split())
        if not normalized:
            return False
        patterns = (
            r"\bssl\s+health\b",
            r"\bssl\s+cert(?:s|ificates?)\b",
            r"\bcertificate\s+health\b",
            r"\bcert(?:ificate)?\s+(?:status|check|health)\b",
            r"\bcert(?:s|ificates?)\b.*\b(good|okay|ok|healthy|health|status|check)\b",
            r"\bcheck\b.*\bssl\s+cert(?:s|ificates?)\b",
            r"\bhow\s+are\s+my\s+ssl\s+cert(?:s|ificates?)\b",
            r"\bmy\s+ssl\s+cert(?:s|ificates?)\b",
            r"\badmserv\b.*\bcert\b",
            r"\bvsite\b.*\bcert\b",
            r"\btls\b.*\bhealth\b",
            r"\bexpired\b.*\bcert",
            r"\bsha1\b.*\bcert",
        )
        return any(re.search(pattern, normalized) for pattern in patterns)

    def _is_server_health_request(self, message: str) -> bool:
        normalized = " ".join((message or "").strip().lower().split())
        if not normalized:
            return False
        patterns = (
            r"\bserver\s+health\b",
            r"\bhealth\s+of\s+(?:this|the)\s+server\b",
            r"\bcheck\s+(?:the\s+)?health\s+of\s+(?:this|the)\s+server\b",
            r"\bcheck\s+(?:this|the)\s+server(?:'s)?\s+health\b",
            r"\boverall\s+server\s+health\b",
            r"\boverall\s+health\s+of\s+(?:this|the)\s+server\b",
            r"\bis\s+(?:this|the)\s+server\s+healthy\b",
            r"\bhow\s+healthy\s+is\s+(?:this|the)\s+server\b",
        )
        return any(re.search(pattern, normalized) for pattern in patterns)

    def _is_php_fpm_health_request(self, message: str) -> bool:
        normalized = " ".join((message or "").strip().lower().split())
        if not normalized:
            return False
        patterns = (
            r"\bphp[-\s]?fpm\b.*\bhealth\b",
            r"\bfpm\b.*\bhealth\b",
            r"\bphp[-\s]?fpm\b.*\bstatus\b",
            r"\bextra\s+php[-\s]?fpm\b",
            r"\bwhich\s+php[-\s]?fpm\b",
            r"\bphp\s+fpm\b.*\bshould\s+be\s+running\b",
        )
        return any(re.search(pattern, normalized) for pattern in patterns)

    def _is_site_health_request(self, message: str) -> bool:
        normalized = " ".join((message or "").strip().lower().split())
        if not normalized:
            return False
        patterns = (
            r"\bvsite\s+health\b",
            r"\bvsite\s+health\s+evidence\b",
            r"\bhealth\s+of\s+the\s+vsite\b",
            r"\bwhat(?:'s| is)?\s+the\s+health\s+of\s+the\s+vsite\b",
            r"\bhow\s+healthy\s+is\s+the\s+vsite\b",
            r"\bis\s+the\s+vsite\s+healthy\b",
            r"\bsite\s+health\b",
            r"\bsite\s+health\s+evidence\b",
            r"\bsite[-\s]?level\s+evidence\b",
            r"\bsite\s+troubleshooting\b",
            r"\bwhat\s+is\s+wrong\s+with\s+(?:this|the|my)\s+site\b",
            r"\bhow\s+healthy\s+is\s+(?:this|the|my)\s+site\b",
            r"\bis\s+(?:this|the|my)\s+site\s+healthy\b",
            r"\bhealth\s+of\s+(?:this|the|my)\s+site\b",
            r"\bevidence\s+for\s+(?:this|the|my)\s+site\b",
        )
        return any(re.search(pattern, normalized) for pattern in patterns)

    def _is_site_health_followup_request(self, message: str) -> bool:
        normalized = " ".join((message or "").strip().lower().split())
        return bool(re.search(r"\b(this|that|same)\s+site\b", normalized))

    async def _remember_site_health_target(self, session_id: str, target: str) -> None:
        session = await self.session_manager.get(session_id)
        config = session.get("config") or {}
        config["last_site_health_target"] = target
        session["config"] = config
        await self.session_manager.save(session_id, session)

    async def _get_last_site_health_target(self, session_id: str) -> str | None:
        session = await self.session_manager.get(session_id)
        config = session.get("config") or {}
        last_target = str(config.get("last_site_health_target", "") or "").strip()
        return last_target or None

    async def _extract_site_health_target(self, message: str, session_id: str) -> str:
        original = (message or "").strip()
        if not original:
            return ""

        patterns = (
            r"(/home/sites/[^\s`\"']+)",
            r"([A-Za-z0-9][A-Za-z0-9._-]*(?:\.[A-Za-z0-9][A-Za-z0-9._-]*)+)",
            r"\b(?:vsite|site)\s+(site\d+)\b",
        )
        for pattern in patterns:
            match = re.search(pattern, original)
            if match:
                target = match.group(1).strip().rstrip("/")
                if target.startswith("/home/sites/"):
                    target = target.split("/home/sites/", 1)[1]
                return target

        if self._is_site_health_followup_request(original):
            last_target = await self._get_last_site_health_target(session_id)
            if last_target:
                return last_target

        return ""

    def _is_web_owner_request(self, message: str) -> bool:
        normalized = " ".join((message or "").strip().lower().split())
        if not normalized:
            return False
        patterns = (
            r"\bweb\s+owner\b",
            r"\bweb\s+ownership\b",
            r"/web\b.*\b(owner|ownership)\b",
            r"\bvalid\s+/web\s+owner\b",
            r"\bensure\b.*\bweb\s+owner\b",
            r"\bfix\b.*\bweb\s+owner\b",
            r"\bwho\s+owns\b.*\/web\b",
            r"\bmake\s+sure\b.*\b(vsites?|sites?)\b.*\bvalid\b.*\b/web\b",
        )
        return any(re.search(pattern, normalized) for pattern in patterns)

    def _is_vsite_creation_request(self, message: str) -> bool:
        normalized = " ".join((message or "").strip().lower().split())
        if not normalized:
            return False
        patterns = (
            r"\bwhy\s+am\s+i\s+unable\s+to\s+create\s+a\s+vsite\b",
            r"\bwhy\s+can't\s+i\s+create\s+a\s+vsite\b",
            r"\bwhy\s+can(?:not|'?t)\s+i\s+create\s+a\s+vsite\b",
            r"\bunable\s+to\s+create\s+a\s+vsite\b",
            r"\bcreate\s+a\s+vsite\b",
            r"\badd\s+a\s+vsite\b",
            r"\bmake\s+a\s+vsite\b",
            r"\bnew\s+vsite\b",
        )
        return any(re.search(pattern, normalized) for pattern in patterns)

    def _is_vsite_inventory_request(self, message: str) -> bool:
        normalized = " ".join((message or "").strip().lower().split())
        if not normalized:
            return False
        patterns = (
            r"\bwhat\s+vsites?\b",
            r"\bwhich\s+vsites?\b",
            r"\blist\s+(?:the\s+)?vsites?\b",
            r"\bshow\s+(?:me\s+)?(?:the\s+)?vsites?\b",
            r"\bwhat\s+(?:sites|domains)\s+does\s+(?:this|the)\s+server\s+host\b",
            r"\bwhich\s+(?:sites|domains)\s+does\s+(?:this|the)\s+server\s+host\b",
            r"\bwhat\s+are\s+the\s+names\s+of\s+(?:these|the)\s+vsites\b",
            r"\bwhat\s+are\s+the\s+domain\s+names\s+of\s+(?:these|the)\s+vsites\b",
            r"\bdomain\s+names?\s+of\s+(?:these|the)\s+vsites\b",
            r"\bhosted\s+domains?\b",
        )
        return any(re.search(pattern, normalized) for pattern in patterns)

    def _vsite_inventory_args(self, message: str) -> dict[str, Any]:
        normalized = " ".join((message or "").strip().lower().split())
        if re.search(r"\b(full|details?|detailed)\b", normalized):
            return {"detail": "full"}
        if re.search(r"\b(names?|internal)\b", normalized):
            return {"detail": "names"}
        return {"detail": "domains"}

    def _is_webroot_integrity_request(self, message: str) -> bool:
        normalized = " ".join((message or "").strip().lower().split())
        if not normalized:
            return False
        patterns = (
            r"\bhacked\b",
            r"\bcompromis(?:ed|e)\b",
            r"\binfected\b",
            r"\bwebshell\b",
            r"\bbackdoor\b",
            r"\bdefaced\b",
            r"\bmalware\b",
            r"\bsuspicious\b.*\b(vsite|site|webroot|web\s+root|directory|document\s+root|website|web\s+site|web)\b",
            r"\b(vsite|site|webroot|web\s+root|directory|document\s+root|website|web\s+site|web)\b.*\bsuspicious\b",
            r"\bcheck\b.*\b(vsite|webroot|directory)\b",
            r"/home/sites/",
            r"/home/\.sites/",
            r"wwwroot/web",
        )
        return any(re.search(pattern, normalized) for pattern in patterns)

    def _extract_webroot_path(self, message: str) -> str | None:
        patterns = (
            r"(/home/sites/[^\s`\"']+/wwwroot/web/?(?:[^\s`\"']*)?)",
            r"(/home/\.sites/[^\s`\"']+/wwwroot/web/?(?:[^\s`\"']*)?)",
        )
        for pattern in patterns:
            match = re.search(pattern, message or "")
            if match:
                return match.group(1).strip().rstrip("/") + "/"
        return None

    def _vsite_webroot_path(self, site_name: str, fqdn: str) -> str:
        site_name = (site_name or "").strip()
        fqdn = (fqdn or "").strip()
        if site_name:
            return f"/home/.sites/{site_name}/wwwroot/web/"
        return f"/home/sites/{fqdn}/wwwroot/web/"

    def _timeline_args(self, message: str) -> dict[str, Any]:
        normalized = " ".join((message or "").strip().lower().split())
        since = "24 hours ago"
        if "yesterday" in normalized:
            since = "yesterday"
        else:
            match = re.search(r"\blast\s+(\d+)\s+(minute|hour|day|week)s?\b", normalized)
            if match:
                since = f"{match.group(1)} {match.group(2)}s ago"
        limit = 60
        match = re.search(r"\btop\s+(\d+)\b", normalized)
        if match:
            limit = max(1, min(int(match.group(1)), 200))
        return {"since": since, "until": "now", "limit": limit}

    def _contains_explicit_path(self, message: str) -> bool:
        normalized = (message or "").strip()
        return bool(re.search(r"/[^\s`\"']+", normalized))

    def _is_webroot_followup_request(self, message: str) -> bool:
        normalized = " ".join((message or "").strip().lower().split())
        if not normalized:
            return False
        patterns = (
            r"\bmore\s+detailed\b",
            r"\bdetailed\b.*\bevaluation\b",
            r"\bmore\s+analysis\b",
            r"\btake\s+a\s+closer\s+look\b",
            r"\bcloser\s+look\b",
            r"\bplease\s+inspect\s+further\b",
            r"\blook\s+again\b",
            r"\banalyze\s+deeper\b",
            r"\bdo\s+a\s+more\s+advanced\b",
            r"\badvanced\s+forensic\b",
        )
        return any(re.search(pattern, normalized) for pattern in patterns)

    async def _remember_webroot_path(self, session_id: str, target_path: str) -> None:
        session = await self.session_manager.get(session_id)
        config = session.get("config") or {}
        config["last_webroot_path"] = target_path
        session["config"] = config
        await self.session_manager.save(session_id, session)

    async def _get_last_webroot_path(self, session_id: str) -> str | None:
        session = await self.session_manager.get(session_id)
        config = session.get("config") or {}
        last_path = str(config.get("last_webroot_path", "") or "").strip()
        return last_path or None

    def _parse_directory_listing(self, output: str, limit: int = 20) -> list[str]:
        rows: list[str] = []
        for line in (output or "").splitlines():
            line = line.strip()
            if not line or line.startswith("("):
                continue
            parts = line.split()
            if len(parts) < 6:
                continue
            kind = parts[1]
            name = parts[-1]
            if "->" in line:
                name = line.split("->", 1)[0].split()[-1]
            rows.append(f"{kind}: {name}")
            if len(rows) >= limit:
                break
        return rows

    def _extract_directory_listing_name(self, line: str) -> str:
        parts = (line or "").split()
        if len(parts) < 6:
            return ""
        name = parts[-1]
        if "->" in line:
            name = line.split("->", 1)[0].split()[-1]
        return name.strip()

    def _looks_like_suspicious_filename(self, name: str) -> bool:
        if not name:
            return False
        basename = name.rsplit("/", 1)[-1].lower()
        if basename in {".htaccess", ".htpasswd"}:
            return False
        suspicious_suffixes = (".php", ".phtml", ".cgi", ".pl", ".py", ".sh", ".js", ".jsp")
        suspicious_markers = (
            "shell",
            "webshell",
            "backdoor",
            "dropper",
            "stager",
            "loader",
            "cmd",
            "upload",
            "tmp",
            "old",
            "copy",
            "cache",
            "rce",
        )
        if basename.startswith(".") and any(marker in basename for marker in suspicious_markers):
            return True
        if any(marker in basename for marker in suspicious_markers) and basename.endswith(suspicious_suffixes):
            return True
        return False

    def _looks_like_weakly_suspicious_filename(self, name: str) -> bool:
        if not name:
            return False
        basename = name.rsplit("/", 1)[-1].lower()
        if basename in {".htaccess", ".htpasswd"}:
            return False
        weak_markers = (
            "backup",
            "old",
            "copy",
            "tmp",
            "cache",
            "test",
            "stage",
            "staging",
            "drop",
            "upload",
            "shell",
        )
        if basename.startswith(".") and basename.endswith((".php", ".phtml", ".cgi", ".pl", ".py", ".sh", ".js", ".jsp")):
            return True
        if any(marker in basename for marker in weak_markers):
            return True
        return False

    def _parse_search_hits(self, output: str, limit: int = 20) -> list[str]:
        rows: list[str] = []
        for line in (output or "").splitlines():
            line = line.strip()
            if not line or line.startswith("("):
                continue
            if "/roundcube/" in line:
                continue
            rows.append(line)
            if len(rows) >= limit:
                break
        return rows

    async def _scan_all_vsites(self) -> str:
        """Scan webroots of all Vsites for compromise indicators.

        Fetches the Vsite list via vsite_list.pl, constructs the webroot
        path for each Vsite from the real site storage layout, and runs
        the webroot integrity scan on each one.
        Returns a combined report.
        """
        try:
            vsite_list_output = await self._get_vsite_list_output()
        except Exception as exc:
            return f"Failed to retrieve Vsite list: {exc}"

        if not vsite_list_output.strip():
            return "No Vsites found on this server."

        vsite_paths: list[tuple[str, str]] = []  # (fqdn, webroot_path)
        for line in vsite_list_output.splitlines():
            parts = line.split()
            if len(parts) < 2:
                continue
            site_name = parts[0].strip()
            fqdn = parts[1].strip()
            webroot = self._vsite_webroot_path(site_name, fqdn)
            vsite_paths.append((fqdn, webroot))

        if not vsite_paths:
            return "No Vsites found on this server."

        total = len(vsite_paths)
        results: list[str] = []
        compromised: list[str] = []
        errors: list[str] = []

        for idx, (fqdn, webroot) in enumerate(vsite_paths, 1):
            try:
                scan_output = await self._get_webroot_integrity_output(webroot)
                results.append(f"--- Vsite {idx}/{total}: {fqdn} ({webroot}) ---\n{scan_output}")
                if "potential compromise indicators" in scan_output.lower():
                    compromised.append(fqdn)
            except Exception as exc:
                errors.append(f"{fqdn}: {exc}")
                results.append(f"--- Vsite {idx}/{total}: {fqdn} ({webroot}) ---\nScan error: {exc}")

        # Build summary
        lines = [f"Webroot integrity scan: {total} Vsite(s) scanned"]
        if compromised:
            lines.append(f"⚠ Potential compromise indicators: {', '.join(compromised)}")
        else:
            lines.append("No compromise indicators found across all Vsites.")
        if errors:
            lines.append(f"Scan errors: {', '.join(errors)}")
        lines.append("")  # blank line before details

        # Add per-Vsite details (truncate if too many)
        if total <= 5:
            lines.extend(results)
        else:
            # For many Vsites, show compromised first, then clean ones as summary
            if compromised:
                for r in results:
                    fqdn_match = next((c for c in compromised if c in r), None)
                    if fqdn_match:
                        lines.append(r)
            lines.append(f"(Detailed results for {total} Vsites available — ask about a specific site for full details)")

        return "\n".join(lines)

    async def _get_webroot_integrity_output(self, target_path: str) -> str:
        forensic_mode = self._is_webroot_forensic_request(self._last_user_message or "")
        path = target_path.rstrip("/") + "/"
        stat_result = await self._execute_tool("stat_path", {"path": path.rstrip("/")}, "root")
        if not stat_result.success:
            return f"Webroot integrity check failed:\n- Path: {path}\n- Error: {stat_result.error}"

        listing_result = await self._execute_tool(
            "list_directory",
            {"path": path, "recursive": False, "max_entries": 80},
            "root",
        )
        scan_pattern = (
            r"eval\s*\(|base64_decode\s*\(|gzinflate\s*\(|shell_exec\s*\(|passthru\s*\(|"
            r"proc_open\s*\(|assert\s*\(|preg_replace\s*\(.*/e|system\s*\(|chmod\s*\("
        )
        content_result = await self._execute_tool(
            "search_files",
            {
                "root": path.rstrip("/"),
                "content_pattern": scan_pattern,
                "max_depth": 6,
                "max_matches": 20,
                "max_file_size_mb": 2,
            },
            "root",
        )

        top_entries = self._parse_directory_listing(listing_result.output, limit=15) if listing_result.success else []
        suspicious_hits = self._parse_search_hits(content_result.output, limit=10) if content_result.success else []
        suspicious_name_hits: list[str] = []
        weak_name_hits: list[str] = []
        if listing_result.success:
            for line in (listing_result.output or "").splitlines():
                name = self._extract_directory_listing_name(line)
                if self._looks_like_suspicious_filename(name):
                    suspicious_name_hits.append(name)
                elif forensic_mode and self._looks_like_weakly_suspicious_filename(name):
                    weak_name_hits.append(name)
                if len(suspicious_name_hits) >= 10:
                    break

        lines = [
            f"Webroot integrity check for: {path}",
            f"Path status: {'ok' if stat_result.success else 'error'}",
        ]

        if top_entries:
            lines.append("Top-level entries:")
            lines.extend(f"- {item}" for item in top_entries)
        elif listing_result.success:
            lines.append("- Top-level entries: none")
        else:
            lines.append(f"- Directory listing error: {listing_result.error}")

        if suspicious_hits:
            lines.append("Strong content hits:")
            lines.extend(f"- {item}" for item in suspicious_hits)
        if suspicious_name_hits:
            lines.append("Strong filename hits:")
            lines.extend(f"- {item}" for item in suspicious_name_hits)

        if forensic_mode and weak_name_hits:
            lines.append("Weak filename signals:")
            lines.extend(f"- {item}" for item in weak_name_hits)

        if suspicious_hits or suspicious_name_hits:
            lines.append("Overall assessment: potential compromise indicators were found. Review the strong hits above.")
        elif forensic_mode and weak_name_hits:
            lines.append(
                "Overall assessment: weak filename signals were found, but no strong webshell or obfuscation indicators were detected."
            )
        else:
            lines.append("Strong content hits: none in the first-pass scan")
            lines.append(
                "Overall assessment: no obvious webshell or obfuscation indicators were found in the first-pass automated scan. "
                "This is not proof that the site is clean."
            )

        return "\n".join(lines)

    def _is_migration_request(self, message: str) -> bool:
        normalized = " ".join((message or "").strip().lower().split())
        if not normalized:
            return False
        patterns = (
            r"\bmigrate\b",
            r"\bmigration\b",
            r"\beasy\s+migrate\b",
            r"\bcmu\b",
            r"\bgmu\b",
            r"\bmove\s+a\s+vsite\b",
            r"\bmove\s+vsite\b",
            r"\btransfer\s+a\s+vsite\b",
            r"\btransfer\s+vsite\b",
        )
        return any(re.search(pattern, normalized) for pattern in patterns)

    def _get_migration_help_output(self) -> str:
        return (
            "BlueOnyx migration:\n"
            "- Use Easy-Migrate for current CLI-based migrations.\n"
            "- CMU is deprecated.\n"
            "- Easy-Backup can also be used for backup/restore-style moves.\n"
            "- Easy-Migrate docs: https://www.blueonyx.it/easy-migrate.html\n"
            "- CMU docs: https://www.blueonyx.it/cmu-migrations.html\n"
            "- Easy-Backup docs: https://www.blueonyx.it/easy-backup.html"
        )

    def _is_gui_500_request(self, message: str) -> bool:
        normalized = " ".join((message or "").strip().lower().split())
        if not normalized:
            return False
        patterns = (
            r"\b500\b",
            r"\binternal\s+server\s+error\b",
            r"\bserver\s+error\b",
            r"\bhttp\s+500\b",
            r"\bfailed\s+with\s+500\b",
            r"\bphp\s+fatal\b",
            r"\btraceback\b",
        )
        return any(re.search(pattern, normalized) for pattern in patterns)

    def _is_cced_transaction_request(self, message: str) -> bool:
        normalized = " ".join((message or "").strip().lower().split())
        if not normalized:
            return False
        patterns = (
            r"\bcced\b",
            r"\b(create|set|find|destroy)\b.*\b(fail|failed|failure|error|denied)\b",
            r"\b(gui|ui|blueonyx)\b.*\b(fail|failed|failure|error|cannot|can't|unable|won't|doesn't\s+work|not\s+work)\b",
            r"\bwhy\b.*\b(fail|failed|failure|error|cannot|can't|unable|won't|doesn't\s+work|not\s+work)\b",
            r"\btransaction\b.*\b(fail|failed|error)\b",
            r"\bconstructor\b.*\b(fail|failed|error)\b",
            r"\bhandler\b.*\b(fail|failed|error)\b",
        )
        return any(re.search(pattern, normalized) for pattern in patterns)

    def _is_account_disk_usage_request(self, message: str) -> bool:
        normalized = " ".join((message or "").strip().lower().split())
        if not normalized:
            return False
        patterns = (
            r"\b(how\s+much|how\s+many|show|tell\s+me|report)\b.*\b(disk\s+space|disk\s+usage|quota|storage)\b.*\b(user|vsite|site)\b",
            r"\b(disk\s+space|disk\s+usage|quota|storage)\b.*\b(user|vsite|site)\b",
            r"\b(user|vsite|site)\b.*\b(disk\s+space|disk\s+usage|quota|storage)\b",
            r"\bhow\s+much\s+disk\s+space\s+does\s+user\b",
            r"\bhow\s+much\s+disk\s+space\s+does\s+vsite\b",
            r"\bhow\s+much\s+disk\s+space\s+does\s+site\b",
            r"\bhow\s+much\s+space\s+does\s+user\b",
            r"\bhow\s+much\s+space\s+does\s+vsite\b",
            r"\bhow\s+much\s+space\s+does\s+site\b",
        )
        return any(re.search(pattern, normalized) for pattern in patterns)

    def _extract_account_disk_usage_args(self, message: str) -> dict[str, Any]:
        normalized = " ".join((message or "").strip().lower().split())
        original = (message or "").strip()
        kind = ""
        target = ""

        def _clean_target(value: str) -> str:
            cleaned = value.strip().strip(".,;:!?\"'`()[]{}")
            cleaned = re.sub(r"^the\s+", "", cleaned, flags=re.IGNORECASE)
            cleaned = re.sub(r"^(?:user|vsite|site|account)\s+", "", cleaned, flags=re.IGNORECASE)
            cleaned = cleaned.strip()
            return cleaned

        user_match = re.search(
            r"\b(?:user|account)\s+([A-Za-z0-9_.@-]+)",
            original,
            re.IGNORECASE,
        )
        vsite_match = re.search(
            r"\b(?:vsite|site)\s+([A-Za-z0-9][A-Za-z0-9._-]*)",
            original,
            re.IGNORECASE,
        )
        if user_match:
            kind = "user"
            target = _clean_target(user_match.group(1))
        elif vsite_match:
            kind = "vsite"
            target = _clean_target(vsite_match.group(1))
        else:
            fqdn_match = re.search(
                r"([A-Za-z0-9][A-Za-z0-9._-]*(?:\.[A-Za-z0-9][A-Za-z0-9._-]*)+)",
                original,
            )
            if fqdn_match:
                kind = "vsite"
                target = _clean_target(fqdn_match.group(1))

        if not kind:
            if "vsite" in normalized or "site " in normalized:
                kind = "vsite"
            else:
                kind = "user"

        return {"kind": kind, "target": target}

    async def _run_cceclient_command(self, command: str) -> str:
        quoted_command = shlex.quote(command)
        proc = await asyncio.create_subprocess_shell(
            f"printf %s {quoted_command} | /usr/sausalito/bin/cceclient",
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.PIPE,
        )
        stdout, stderr = await asyncio.wait_for(proc.communicate(), timeout=10.0)
        if proc.returncode != 0:
            error_msg = stderr.decode("utf-8", errors="replace").strip()
            raise RuntimeError(error_msg or f"cceclient command failed with exit code {proc.returncode}")
        return stdout.decode("utf-8", errors="replace").strip()

    async def _get_vsite_list_output(self) -> str:
        cmd = ["/usr/sausalito/sbin/vsite_list.pl"]
        if os.geteuid() != 0:
            cmd = ["sudo", "-n", "/usr/sausalito/sbin/vsite_list.pl"]

        proc = await asyncio.create_subprocess_exec(
            *cmd,
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.PIPE,
        )
        stdout, stderr = await asyncio.wait_for(proc.communicate(), timeout=20.0)
        if proc.returncode != 0:
            error_msg = stderr.decode("utf-8", errors="replace").strip()
            raise RuntimeError(error_msg or f"vsite_list.pl failed with exit code {proc.returncode}")
        return stdout.decode("utf-8", errors="replace").strip()

    def _resolve_vsite_name_from_list(self, identifier: str, vsite_list_output: str) -> str:
        candidate = (identifier or "").strip()
        if not candidate:
            return ""

        candidate_fqdn = candidate.lower()
        candidate_host = candidate_fqdn.split(".", 1)[0] if "." in candidate_fqdn else candidate_fqdn

        for line in (vsite_list_output or "").splitlines():
            parts = line.split()
            if len(parts) < 2:
                continue
            group_name = parts[0].strip()
            fqdn = parts[1].strip().lower()
            host = fqdn.split(".", 1)[0] if "." in fqdn else fqdn

            if candidate_fqdn == fqdn:
                return group_name
            if candidate_host == host and "." not in candidate_fqdn:
                return group_name

        return ""

    async def _resolve_vsite_name(self, identifier: str) -> str:
        candidate = (identifier or "").strip()
        if not candidate:
            return ""

        vsite_list_output = await self._get_vsite_list_output()
        resolved = self._resolve_vsite_name_from_list(candidate, vsite_list_output)
        if resolved:
            return resolved

        if "." in candidate:
            return candidate.split(".", 1)[0]
        return candidate

    async def _get_quotas_output(self, kind: str) -> str:
        cmd = ["get_quotas.pl", "--users"] if kind == "user" else ["get_quotas.pl", "--sites"]
        if os.geteuid() != 0:
            cmd = ["sudo", "-n", "/usr/sausalito/sbin/get_quotas.pl", "--users"] if kind == "user" else ["sudo", "-n", "/usr/sausalito/sbin/get_quotas.pl", "--sites"]
        else:
            cmd = ["/usr/sausalito/sbin/get_quotas.pl", "--users"] if kind == "user" else ["/usr/sausalito/sbin/get_quotas.pl", "--sites"]

        proc = await asyncio.create_subprocess_exec(
            *cmd,
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.PIPE,
        )
        stdout, stderr = await asyncio.wait_for(proc.communicate(), timeout=20.0)
        if proc.returncode != 0:
            error_msg = stderr.decode("utf-8", errors="replace").strip()
            raise RuntimeError(error_msg or f"get_quotas.pl failed with exit code {proc.returncode}")
        return stdout.decode("utf-8", errors="replace").strip()

    def _format_kb(self, value: int) -> str:
        if value <= 0:
            return "unlimited"
        mb = value / 1024.0
        if mb >= 1024:
            gb = mb / 1024.0
            return f"{value} KB ({gb:.1f} GB)"
        return f"{value} KB ({mb:.1f} MB)"

    def _parse_quotas_line(self, output: str, target: str) -> tuple[int, int] | None:
        for line in (output or "").splitlines():
            parts = line.split()
            if len(parts) < 3:
                continue
            if parts[0] != target:
                continue
            try:
                used = int(parts[1])
            except ValueError:
                used = 0
            try:
                quota = int(parts[2])
            except ValueError:
                quota = 0
            return used, quota
        return None

    async def _get_account_disk_usage_output(self, kind: str, target: str) -> str:
        kind = (kind or "").strip().lower()
        target = (target or "").strip()
        if not target:
            raise RuntimeError("Missing disk usage target")

        if kind == "vsite":
            resolved_site = await self._resolve_vsite_name(target)
            quotas_output = await self._get_quotas_output("vsite")
            parsed = self._parse_quotas_line(quotas_output, resolved_site)
            if not parsed and resolved_site != target:
                parsed = self._parse_quotas_line(quotas_output, target)
            if not parsed and "." in target:
                parsed = self._parse_quotas_line(quotas_output, target.split(".", 1)[0])
            if not parsed:
                raise RuntimeError(f"Could not find Vsite '{target}' in live quota output")

            used_kb, quota_kb = parsed
            percent = round(100.0 * used_kb / quota_kb, 1) if quota_kb > 0 else 0.0
            quota_text = self._format_kb(quota_kb) if quota_kb > 0 else "unlimited"
            return "\n".join(
                [
                    "Vsite Disk Usage:",
                    f"- Vsite: {target}",
                    f"- Group: {resolved_site}",
                    f"- Used: {self._format_kb(used_kb)}",
                    f"- Quota: {quota_text}",
                    f"- Status: {percent:.1f}% of quota used" if quota_kb > 0 else "- Status: no quota limit",
                ]
            )

        quotas_output = await self._get_quotas_output("user")
        parsed = self._parse_quotas_line(quotas_output, target)
        if not parsed:
            raise RuntimeError(f"Could not find user '{target}' in live quota output")

        used_kb, quota_kb = parsed
        quota_text = self._format_kb(quota_kb) if quota_kb > 0 else "unlimited"
        percent = round(100.0 * used_kb / quota_kb, 1) if quota_kb > 0 else 0.0
        return "\n".join(
            [
                "User Disk Usage:",
                f"- User: {target}",
                f"- Used: {self._format_kb(used_kb)}",
                f"- Quota: {quota_text}",
                f"- Status: {percent:.1f}% of quota used" if quota_kb > 0 else "- Status: no quota limit",
            ]
        )

    def _get_vsite_creation_explanation(self) -> str:
        return "\n".join(
            [
                "Vsite Creation:",
                "Create Vsites in the BlueOnyx GUI under Site Management using the `+ Add` button.",
                "If the button is missing or creation is denied, check the current account's `manageSite` capability.",
                "A second blocker can be the Vsite limit (`maxVsite`) or related allocation limits.",
                "If creation still fails, verify the account's site-management rights and the current Vsite limit.",
            ]
        )

    async def _get_cced_transaction_failure_output(self, message: str) -> str:
        pattern = (
            r"cced\(.*\).*(CREATE|SET|FIND|DESTROY).*(failed|failure|error)|"
            r"(CREATE|SET|FIND|DESTROY).*(failed|failure|error)|"
            r"failed \(-?[0-9]+\)|"
            r"handler.*failed|"
            r"constructor.*failed|"
            r"exception.*handler|"
            r"cce.*failed"
        )
        result = await self._execute_tool(
            "search_admin_logs",
            {
                "pattern": pattern,
                "paths": ["/var/log/messages*"],
                "context_lines": 12,
                "max_matches": 20,
                "ignore_case": True,
            },
            "root",
        )
        if not result.success:
            raise RuntimeError(result.error or "Failed to search CCEd transaction logs")

        output = (result.output or "").strip()
        if self._is_no_matches_output(output):
            return "\n".join(
                [
                    "CCEd Transaction Check:",
                    "No failed CREATE/SET/FIND/DESTROY transaction lines were found in /var/log/messages*.",
                    "If the GUI still fails, inspect the 10-20 lines before the failing cced entry for handler or constructor errors.",
                ]
            )

        return "\n".join(
            [
                "CCEd Transaction Failure:",
                "Look at the failed CCEd transaction line and the surrounding context before it.",
                "The handler or constructor error is usually 10-20 lines earlier.",
                "",
                output,
            ]
        )

    def _get_gui_500_advice(self) -> str:
        return "\n".join(
            [
                "GUI 500 Error:",
                "Set `/usr/sausalito/ui/chorizo/ci4/.env` to development mode while debugging:",
                "CI_ENVIRONMENT = development",
                "#CI_ENVIRONMENT = production",
                "That exposes the detailed error in the browser. Switch it back to production after debugging.",
            ]
        )

    def _is_blueonyx_support_context(self, message: str) -> bool:
        normalized = " ".join((message or "").strip().lower().split())
        if not normalized:
            return False
        patterns = (
            r"\bblueonyx\b",
            r"\bgui\b",
            r"\bweb\s+ui\b",
            r"\bcontrol\s+panel\b",
            r"\bsettings\b",
            r"\bconfiguration\b",
            r"\bcreate\b.*\bvsite\b",
            r"\bserver\s+error\b",
            r"\binternal\s+server\s+error\b",
            r"\b500\b",
            r"\bcce\b",
        )
        return any(re.search(pattern, normalized) for pattern in patterns)

    def _is_blueonyx_knowledge_request(self, message: str) -> bool:
        normalized = " ".join((message or "").strip().lower().split())
        if not normalized:
            return False
        blueonyx_terms = (
            r"\bblueonyx\b",
            r"\bsausalito\b",
            r"\bcce\b",
            r"\bcodb\b",
            r"\bvsite\b",
            r"\bsiteadmin\b",
            r"\bactive\s+monitor\b",
            r"\bswatch\b",
            r"\badmserv\b",
            r"\bchorizo\b",
            r"\bconstructor\b",
            r"\bhandler\b",
            r"\bschema\b",
            r"\bglue\b",
            r"\bget_quotas\.pl\b",
            r"\bvsite_list\.pl\b",
            r"\bquota\b",
            r"\bmail\s+statistics\b",
            r"\bmail\s+health\b",
            r"\bservice_api_key\b",
        )
        question_patterns = (
            r"\bhow\s+do\s+i\b",
            r"\bhow\s+to\b",
            r"\bwhat\s+is\b",
            r"\bwhat's\b",
            r"\bwhy\s+does\b",
            r"\bwhy\s+is\b",
            r"\bwhere\s+is\b",
            r"\bexplain\b",
            r"\btell\s+me\b",
            r"\bshow\s+me\b",
            r"\bhow\s+can\s+i\b",
        )
        return any(re.search(term, normalized) for term in blueonyx_terms) and any(re.search(q, normalized) for q in question_patterns)

    def _is_support_channels_request(self, message: str) -> bool:
        normalized = " ".join((message or "").strip().lower().split())
        if not normalized:
            return False
        patterns = (
            r"\bwhere\s+can\s+i\s+get\s+support\b",
            r"\bhow\s+do\s+i\s+get\s+support\b",
            r"\bhow\s+can\s+i\s+get\s+support\b",
            r"\bwhere\s+do\s+i\s+ask\s+for\s+help\b",
            r"\bwhat\s+support\s+channels\b",
            r"\bsupport\s+channels\b",
            r"\bcontact\s+support\b",
            r"\bhelp\s+and\s+support\b",
            r"\bhow\s+do\s+i\s+open\s+a\s+ticket\b",
            r"\bsupport\s+request\b",
        )
        return any(re.search(pattern, normalized) for pattern in patterns)

    def _get_support_channels_output(self) -> str:
        return (
            "BlueOnyx support channels:\n"
            "- Website: https://www.blueonyx.it/\n"
            "- Wiki: https://wiki.blueonyx.it/userguide/start\n"
            "- Discord: https://discord.gg/YJ2MHDvyrB\n"
            "- Mailing list archive: https://mail.blueonyx.it/pipermail/blueonyx/\n"
            "- Mailing list membership: https://lists.blueonyx.it:81/mailman/listinfo/blueonyx\n"
            "- Support request in the GUI: Software Updates > Support > Support Request"
        )

    def _has_uncertain_answer(self, text: str) -> bool:
        normalized = " ".join((text or "").strip().lower().split())
        if not normalized:
            return True
        patterns = (
            r"\bi\s+don't\s+know\b",
            r"\bi\s+cannot\s+determine\b",
            r"\bcan't\s+determine\b",
            r"\bnot\s+enough\s+information\b",
            r"\bno\s+matching\s+entries\b",
            r"\bunable\s+to\s+determine\b",
            r"\bi\s+am\s+not\s+sure\b",
            r"\bno\s+evidence\b",
            r"\bunknown\b",
        )
        return any(re.search(pattern, normalized) for pattern in patterns)

    async def _maybe_append_support_hint(self, session_id: str, message: str, output: str) -> str:
        if not self._is_blueonyx_support_context(message):
            return output
        if not self._has_uncertain_answer(output):
            return output

        session = await self.session_manager.get(session_id)
        config = session.get("config") or {}
        if config.get("support_hint_used"):
            return output

        config["support_hint_used"] = True
        session["config"] = config
        await self.session_manager.save(session_id, session)

        return output + "\n\nNeed deeper help? Use Software Updates > Support > Support Request."

    async def _get_disk_space_output(self) -> str:
        proc = await asyncio.create_subprocess_exec(
            "df",
            "-h",
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.PIPE,
        )
        stdout, stderr = await asyncio.wait_for(proc.communicate(), timeout=5.0)
        if proc.returncode != 0:
            error_msg = stderr.decode("utf-8", errors="replace").strip()
            raise RuntimeError(f"Error executing df: {error_msg}")
        return stdout.decode("utf-8", errors="replace").strip() or "(No output)"

    def _summarize_df_output(self, output: str) -> str:
        lines = [line.rstrip() for line in (output or "").splitlines() if line.strip()]
        if len(lines) < 2:
            return output.strip() or "(No output)"

        header = lines[0]
        rows: list[dict[str, Any]] = []
        for line in lines[1:]:
            parts = line.split()
            if len(parts) < 6:
                continue
            filesystem = parts[0]
            size = parts[1]
            used = parts[2]
            avail = parts[3]
            use_pct = parts[4]
            mount = " ".join(parts[5:])
            try:
                pct_num = int(use_pct.rstrip("%"))
            except ValueError:
                pct_num = -1
            rows.append(
                {
                    "filesystem": filesystem,
                    "size": size,
                    "used": used,
                    "avail": avail,
                    "use_pct": use_pct,
                    "pct_num": pct_num,
                    "mount": mount,
                }
            )

        if not rows:
            return output.strip() or "(No output)"

        rows.sort(key=lambda row: row["pct_num"], reverse=True)
        warning_rows = [row for row in rows if isinstance(row["pct_num"], int) and row["pct_num"] >= 80]
        critical_rows = [row for row in rows if isinstance(row["pct_num"], int) and row["pct_num"] >= 90]
        top_rows = rows[:5]
        low_free_rows = sorted(rows, key=lambda row: self._free_space_sort_key(row["avail"]))

        summary: list[str] = [
            "Disk Health Summary:",
            f"Filesystems scanned: {len(rows)}",
        ]

        if critical_rows:
            summary.append(f"Alert: {len(critical_rows)} filesystem(s) are at or above 90% used.")
        elif warning_rows:
            summary.append(f"Warning: {len(warning_rows)} filesystem(s) are at or above 80% used.")
        else:
            summary.append("Status: no filesystem is above 80% used.")

        summary.append("")
        summary.append("Top used mounts:")
        for row in top_rows:
            summary.append(
                f"- {row['mount']}: {row['use_pct']} used "
                f"({row['avail']} free of {row['size']}) [{row['filesystem']}]"
            )

        summary.append("")
        summary.append("Lowest free space mounts:")
        for row in low_free_rows[:3]:
            summary.append(
                f"- {row['mount']}: {row['avail']} free, {row['use_pct']} used"
            )

        summary.append("")
        summary.append("Header:")
        summary.append(f"- {header}")
        return "\n".join(summary)

    def _free_space_sort_key(self, value: str) -> float:
        normalized = (value or "").strip().lower()
        if not normalized:
            return float("inf")
        match = re.match(r"^([0-9]+(?:\.[0-9]+)?)([kmgtp]?)$", normalized)
        if not match:
            return float("inf")
        magnitude = float(match.group(1))
        unit = match.group(2)
        scale = {
            "": 1,
            "k": 1024,
            "m": 1024**2,
            "g": 1024**3,
            "t": 1024**4,
            "p": 1024**5,
        }.get(unit, 1)
        return magnitude * scale

    async def _get_disk_health_output(self) -> str:
        output = await self._get_disk_space_output()
        return self._summarize_df_output(output)

    def _is_admin_log_request(self, message: str) -> bool:
        """
        Detect common admin log investigation requests.

        These are best handled deterministically so the model does not invent a
        diagnosis when the user explicitly wants evidence from logs.
        """
        normalized = " ".join((message or "").strip().lower().split())
        if not normalized:
            return False

        patterns = (
            r"\bfailed\s+login(s| attempts)?\b",
            r"\bfailed\s+authentication(s)?\b",
            r"\bauthentication\s+failure\b",
            r"\bauth\s+failed\b",
            r"\binvalid\s+user\b",
            r"\bpam_unix\b",
            r"\bsasl\b",
            r"\bmail\s+delivery\b",
            r"\bmail\s+log\b",
            r"/var/log/(maillog|secure|messages|cron|httpd|dovecot|sshd|audit\.log)",
            r"\bcheck\b.*\blog(s)?\b",
            r"\bsearch\b.*\blog(s)?\b",
            r"\breview\b.*\blog(s)?\b",
            r"\binspect\b.*\blog(s)?\b",
        )
        return any(re.search(pattern, normalized) for pattern in patterns)

    def _estimate_tokens(self, text: str) -> int:
        if not text:
            return 0
        return max(1, len(text) // 4)

    def _truncate_content(self, content: Any, max_chars: int = 1800) -> Any:
        if not isinstance(content, str):
            return content
        if len(content) <= max_chars:
            return content
        head = max_chars // 2
        tail = max_chars - head
        omitted = len(content) - (head + tail)
        return content[:head] + f"\n...[truncated {omitted} chars]...\n" + content[-tail:]

    def _prepare_messages_for_llm(self, messages: list[dict[str, Any]]) -> list[dict[str, Any]]:
        if not messages:
            return messages

        prepared: list[dict[str, Any]] = []
        system_msg = messages[0] if messages and messages[0].get("role") == "system" else None
        if system_msg:
            prepared.append(dict(system_msg))

        recent: list[dict[str, Any]] = []
        token_budget = 2400
        max_messages = 18
        token_count = self._estimate_tokens(str(system_msg.get("content", ""))) if system_msg else 0

        for msg in reversed(messages[1:] if system_msg else messages):
            normalized = dict(msg)
            normalized["content"] = self._truncate_content(normalized.get("content", ""))
            content_text = normalized.get("content", "")
            if not isinstance(content_text, str):
                content_text = json.dumps(content_text, ensure_ascii=False)
            estimate = self._estimate_tokens(content_text) + 10
            if recent and (len(recent) >= max_messages or token_count + estimate > token_budget):
                continue
            recent.append(normalized)
            token_count += estimate

        prepared.extend(reversed(recent))
        return prepared

    def _is_failed_login_request(self, message: str) -> bool:
        normalized = " ".join((message or "").strip().lower().split())
        if not normalized:
            return False
        keywords = (
            "failed login",
            "failed logins",
            "failed login attempts",
            "failed authentication",
            "authentication failure",
            "login failures",
        )
        return any(keyword in normalized for keyword in keywords)

    def _is_login_attempt_request(self, message: str) -> bool:
        normalized = " ".join((message or "").strip().lower().split())
        if not normalized:
            return False
        keywords = (
            "login attempt",
            "login attempts",
            "auth attempt",
            "auth attempts",
            "attempted login",
            "preauth",
            "auth activity",
            "login activity",
            "authenticating user",
            "connection closed by authenticating user",
            "disconnected from authenticating user",
        )
        return any(keyword in normalized for keyword in keywords)

    def _is_all_logs_request(self, message: str) -> bool:
        normalized = " ".join((message or "").strip().lower().split())
        if not normalized:
            return False
        keywords = (
            "all logs",
            "every log",
            "entire logs",
            "whole logs",
            "all admin logs",
            "scan logs",
            "search logs",
            "review logs",
            "inspect logs",
            "check logs",
        )
        return any(keyword in normalized for keyword in keywords)

    def _is_detailed_log_request(self, message: str) -> bool:
        normalized = " ".join((message or "").strip().lower().split())
        if not normalized:
            return False
        keywords = (
            "detailed",
            "details",
            "evidence",
            "forensics",
            "forensic",
            "show the lines",
            "show lines",
            "full output",
            "raw output",
            "exact entries",
            "exact lines",
            "what exactly",
        )
        return any(keyword in normalized for keyword in keywords)

    def _is_webroot_forensic_request(self, message: str) -> bool:
        normalized = " ".join((message or "").strip().lower().split())
        if not normalized:
            return False
        patterns = (
            r"\bforensic\b",
            r"\bforensics\b",
            r"\bdetailed\b.*\bwebroot\b",
            r"\bdeep\b.*\binspect\b.*\bwebroot\b",
            r"\bshow\b.*\bdetails\b",
            r"\bwhat exactly\b",
            r"\ball suspicious\b",
            r"\bweak signals\b",
        )
        return any(re.search(pattern, normalized) for pattern in patterns)

    def _is_mail_stats_request(self, message: str) -> bool:
        normalized = " ".join((message or "").strip().lower().split())
        if not normalized:
            return False
        patterns = (
            r"\b(how many|count|number of|statistics|stats|totals?|volume)\b.*\b(mail|email|emails|messages)\b",
            r"\b(mail|email|emails|messages)\b.*\b(how many|count|number of|statistics|stats|totals?|volume)\b",
            r"\b(inbound|outbound|sent|received|rejected|deferred|bounced|spam)\b.*\b(mail|email|emails|messages)\b",
            r"\b(mail|email|emails|messages)\b.*\b(inbound|outbound|sent|received|rejected|deferred|bounced|spam)\b",
            r"\banzahl\b.*\b(mail|email|emails|messages|spam|reject|abgelehnt|eingehend|ausgehend)\b",
        )
        return any(re.search(pattern, normalized) for pattern in patterns)

    def _mail_stats_args(self, message: str) -> dict[str, Any]:
        normalized = " ".join((message or "").strip().lower().split())
        user = ""
        days = 0
        email_match = re.search(r"([A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,})", message or "")
        if email_match:
            user = email_match.group(1)
        else:
            match = re.search(r"\b(?:for|user|account|sender|recipient)\s+([A-Za-z0-9_.@+-]+)", normalized)
            if match:
                candidate = match.group(1)
                if candidate not in {"mail", "mails", "email", "emails", "message", "messages", "stats", "statistics", "count", "counts", "number"}:
                    user = candidate

        if any(token in normalized for token in ("today", "last 24h", "past 24h", "last day", "past day")):
            days = 1
        elif "yesterday" in normalized:
            days = 2
        elif any(token in normalized for token in ("this week", "last week", "past week", "recent")):
            days = 7
        elif any(token in normalized for token in ("this month", "last month", "past month")):
            days = 30

        args: dict[str, Any] = {
            "paths": ["/var/log/maillog*"],
            "limit": 5,
            "days": days,
        }
        if user:
            args["user"] = user
        return args

    def _is_mail_health_request(self, message: str) -> bool:
        normalized = " ".join((message or "").strip().lower().split())
        if not normalized:
            return False
        patterns = (
            r"\b(mail|email|emails|messages)\b.*\b(health|healthy|status|trend|trends|report)\b",
            r"\b(health|healthy|status|trend|trends|report)\b.*\b(mail|email|emails|messages)\b",
            r"\b(smtp|postfix|dovecot|spam)\b.*\b(health|healthy|status|trend|trends)\b",
        )
        return any(re.search(pattern, normalized) for pattern in patterns)

    def _is_general_suspicion_request(self, message: str) -> bool:
        normalized = " ".join((message or "").strip().lower().split())
        if not normalized:
            return False
        patterns = (
            r"\banything\s+suspicious\b",
            r"\bsomething\s+suspicious\b",
            r"\bany(?:thing)?\s+unusual\b",
            r"\bany(?:thing)?\s+odd\b",
            r"\bany(?:thing)?\s+concerning\b",
            r"\banything\s+off\b",
            r"\banything\s+wrong\b",
            r"\bsuspicious\s+activity\b",
            r"\bdoes\s+anything\s+look\s+wrong\b",
            r"\bdoes\s+anything\s+look\s+off\b",
            r"\bdoes\s+anything\s+look\s+suspicious\b",
        )
        return any(re.search(pattern, normalized) for pattern in patterns)

    async def _get_general_suspicion_output(self, message: str) -> str:
        lines: list[str] = ["Broad Suspicion Check", ""]

        health_result = await self._execute_tool("server_health_summary", {}, "root")
        if health_result.success and (health_result.output or "").strip():
            lines.append("=== Server Health ===")
            lines.append((health_result.output or "").strip())
            lines.append("")

        mail_result = await self._execute_tool("mail_health", self._mail_health_args(message), "root")
        if mail_result.success and (mail_result.output or "").strip():
            lines.append("=== Mail Health ===")
            lines.append((mail_result.output or "").strip())
            lines.append("")

        abuse_result = await self._execute_tool(
            "spam_abuse",
            {"paths": ["/var/log/maillog*"], "days": 7, "limit": 5},
            "root",
        )
        if abuse_result.success and (abuse_result.output or "").strip():
            lines.append("=== Spam / Abuse Check ===")
            lines.append((abuse_result.output or "").strip())
            lines.append("")

        log_result = await self._execute_tool(
            "search_admin_logs",
            {
                "pattern": r"failed|error|denied|reject|rejected|deferred|bounced|segfault|panic|critical|oom|out of memory|invalid user|authentication failure",
                "paths": [
                    "/var/log/messages*",
                    "/var/log/secure*",
                    "/var/log/maillog*",
                    "/var/log/httpd/*",
                    "/var/log/audit/audit.log*",
                ],
                "max_matches": 20,
                "ignore_case": True,
            },
            "root",
        )
        lines.append("=== Recent Admin / Security Signals ===")
        if log_result.success and (log_result.output or "").strip() and not self._is_no_matches_output(log_result.output):
            lines.append(self._format_admin_log_result(message, log_result.output, detail_mode=False))
        else:
            lines.append("No obvious suspicious signals were found in the inspected admin logs.")

        return "\n".join(lines).strip()

    def _is_email_problem_request(self, message: str) -> bool:
        """Detect email delivery/problem questions that need multi-source diagnosis.

        Matches questions like "Why can't jdoe send emails?",
        "email not working for user X", "mail delivery failed",
        but NOT "how many emails" (mail_stats) or "mail health" (mail_health).
        """
        normalized = " ".join((message or "").strip().lower().split())
        if not normalized:
            return False
        # Must contain a mail/email keyword
        if not re.search(r"\b(mail|email|emails|smtp|postfix|dovecot|send|sent|sending|receive|receiving|delivery|deliver|nachricht|nachrichten|versand|empfang)\b", normalized):
            return False
        # Must contain a problem/failure keyword
        problem_patterns = (
            r"\b(can'?t|cannot|can not|won'?t|unable|fail|failed|failure|reject|rejected|deferred|bounce|bounced|block|blocked|problem|trouble|issue|not working|doesn'?t work|does not work|nicht|keine|kann|problem|fehler|abgelehnt)\b",
            r"\b(why|how come|what(?:'?s| is) wrong|investigate|debug|troubleshoot|diagnos)\b",
        )
        if not any(re.search(p, normalized) for p in problem_patterns):
            return False
        # Exclude pure stats/health requests (those have their own shortcuts)
        if re.search(r"\b(how many|count|number of|statistics|stats|totals?|volume)\b", normalized):
            if not re.search(r"\b(fail|reject|defer|bounce|block|problem|can'?t|cannot|unable|not working)\b", normalized):
                return False
        if re.search(r"\b(health|healthy|status|trend|trends|report)\b", normalized):
            if not re.search(r"\b(fail|reject|defer|bounce|block|problem|can'?t|cannot|unable|not working)\b", normalized):
                return False
        # Exclude autoconfig/setup requests
        if re.search(r"\b(setup|configure|account|outlook|thunderbird|apple\s?mail|autoconfig|autoconfiguration|einricht)\b", normalized):
            return False
        return True

    def _is_email_autoconfig_request(self, message: str) -> bool:
        normalized = " ".join((message or "").strip().lower().split())
        if not normalized:
            return False
        setup_patterns = (
            r"\b(email|mail)\b.*\b(account|client|setup|configure|configuration|outlook|thunderbird|apple\s+mail|autoconfig|autoconfiguration)\b",
            r"\b(outlook|thunderbird|apple\s+mail|iphone\s+mail|ios\s+mail|android\s+mail|gmail\s+app)\b.*\b(email|mail|account|setup|configure|configuration)\b",
            r"\bhow\s+can\s+i\b.*\b(email|mail)\b.*\b(setup|configure|account)\b",
            r"\bhow\s+do\s+i\b.*\b(email|mail)\b.*\b(setup|configure|account)\b",
            r"\beinricht(en|e)\b.*\b(email|mail)\b",
        )
        return any(re.search(pattern, normalized) for pattern in setup_patterns)

    def _is_spam_abuse_request(self, message: str) -> bool:
        """Detect spam/abuse investigation questions.

        Matches: "which account is sending spam", "who is blasting spam",
        "email abuse", "compromised account sending spam", "spam offender",
        "welcher Account versendet Spam", "SPAM versendet".
        Does NOT match: "how many emails" (mail_stats), "mail health" (mail_health),
        "can't send email" (email_problem), "setup email" (autoconfig).
        """
        normalized = " ".join((message or "").strip().lower().split())
        if not normalized:
            return False
        # Must contain a spam/abuse keyword
        if not re.search(r"\b(spam|abuse|abusive|compromised|hacked|blast|blasting|bulk|bulkmail|bulk.?mail|mass.?mail|junk|offender|verspam|spamversand|spam.?versand|spammer)\\b", normalized):
            # Also match "who is sending" + mail/email context
            if not re.search(r"\b(who|welcher|welche|welches)\b.*\b(sending|versend|verschick)\\b.*\b(mail|email|emails|nachricht|nachrichten)\\b", normalized):
                return False
        # Must contain a mail/email keyword (unless spam alone is enough)
        if not re.search(r"\b(spam|abuse|compromised|verspam|spamversand|spammer)\\b", normalized):
            if not re.search(r"\b(mail|email|emails|smtp|postfix|account|konto|user|nachricht|nachrichten)\\b", normalized):
                return False
        # Exclude pure stats/health/problem/setup requests
        if re.search(r"\b(how many|count|statistics|stats|totals?|volume)\\b", normalized):
            if not re.search(r"\b(spam|abuse|compromised|blast)\\b", normalized):
                return False
        if re.search(r"\b(health|healthy|status|trend|trends|report)\\b", normalized):
            if not re.search(r"\b(spam|abuse|compromised|blast)\\b", normalized):
                return False
        if re.search(r"\b(can'?t|cannot|unable|fail|failed|not working)\\b", normalized):
            if not re.search(r"\b(spam|abuse|compromised|blast)\\b", normalized):
                return False
        if re.search(r"\b(setup|configure|outlook|thunderbird|autoconfig)\\b", normalized):
            return False
        return True

    def _spam_abuse_args(self, message: str) -> dict[str, Any]:
        """Build arguments for the spam_abuse tool from a user message."""
        normalized = " ".join((message or "").strip().lower().split())
        user = ""
        email_match = re.search(r"([A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,})", message or "")
        if email_match:
            user = email_match.group(1)
        else:
            match = re.search(r"\b(?:for|user|account|von|fuer)\s+([A-Za-z0-9_.@+-]+)", normalized)
            if match:
                candidate = match.group(1)
                if candidate not in {"spam", "mail", "email", "emails", "the", "a", "my"}:
                    user = candidate
        days = 7  # Default: last 7 days
        if any(token in normalized for token in ("today", "last 24h", "past 24h", "last day", "past day")):
            days = 1
        elif "yesterday" in normalized:
            days = 2
        elif any(token in normalized for token in ("this week", "last week", "past week", "recent")):
            days = 7
        elif any(token in normalized for token in ("this month", "last month", "past month")):
            days = 30
        args: dict[str, Any] = {
            "paths": ["/var/log/maillog*"],
            "limit": 10,
            "days": days,
        }
        if user:
            args["user"] = user
        return args

    def _mail_health_args(self, message: str) -> dict[str, Any]:
        args = self._mail_stats_args(message)
        if int(args.get("days", 0) or 0) <= 0:
            args["days"] = 7
        args["limit"] = min(int(args.get("limit", 3) or 3), 3)
        return args

    async def _diagnose_email_problem(self, message: str) -> str:
        """Combine mail_health and admin log search for email problem diagnosis.

        Extracts any user/email from the message, then runs:
        1. mail_health with user filter (delivery status + spam stats)
        2. search_admin_logs for mail-related errors involving that user
        Returns a combined diagnostic summary.
        """
        # Extract user/email from message
        user = ""
        email_match = re.search(r"([A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,})", message or "")
        if email_match:
            user = email_match.group(1)
        else:
            match = re.search(r"\b(?:for|user|account|sender|recipient|von|fuer)\s+([A-Za-z0-9_.@+-]+)", " ".join((message or "").strip().lower().split()))
            if match:
                candidate = match.group(1)
                if candidate not in {"mail", "mails", "email", "emails", "message", "messages", "not", "a", "the", "my"}:
                    user = candidate

        lines: list[str] = []
        lines.append("=== Email Problem Diagnosis ===")
        if user:
            lines.append(f"User: {user}")
        lines.append("")

        # 1. Run mail_health with user filter
        health_args: dict[str, Any] = {"paths": ["/var/log/maillog*"], "days": 7, "limit": 3}
        if user:
            health_args["user"] = user
        health_result = await self._execute_tool("mail_health", health_args, "root")
        if health_result.success and (health_result.output or "").strip():
            lines.append("--- Mail Health ---")
            lines.append((health_result.output or "").strip())
            lines.append("")

        # 2. Search admin logs for mail-related errors involving the user
        log_query = "mail error"
        if user:
            log_query = f"{user} mail error"
        log_args = {
            "query": log_query,
            "paths": ["/var/log/maillog*", "/var/log/messages*"],
            "limit": 20,
        }
        log_result = await self._execute_tool("search_admin_logs", log_args, "root")
        if log_result.success and (log_result.output or "").strip():
            lines.append("--- Relevant Log Entries ---")
            lines.append((log_result.output or "").strip())
            lines.append("")

        # 3. Check postfix/dovecot service status
        for svc in ("postfix", "dovecot"):
            svc_result = await self._execute_tool("service_status", {"service": svc}, "root")
            if svc_result.success and (svc_result.output or "").strip():
                lines.append(f"--- {svc.title()} Status ---")
                lines.append((svc_result.output or "").strip())
                lines.append("")

        result = "\n".join(lines)
        return result

    def _format_email_autoconfig_output(self, query: str) -> str:
        lines: list[str] = [
            "Email Autoconfiguration:",
            "Use the BlueOnyx GUI autoconfiguration data, not generic mail defaults.",
            "Key points:",
            "- Server: use the exact IMAP and SMTP hostnames from BlueOnyx.",
            "- Ports: use the exact IMAP and SMTP ports from BlueOnyx.",
            "- Encryption: use the exact SSL/TLS or STARTTLS mode from BlueOnyx.",
            "- Login: use the login format shown in the GUI, usually the full email address.",
        ]
        lines.extend(
            [
                "",
                "If you need the exact values, open Email Autoconfiguration in the BlueOnyx GUI.",
            ]
        )
        return "\n".join(lines).strip()

    def _is_no_matches_output(self, output: str | None) -> bool:
        normalized = " ".join((output or "").strip().lower().split())
        return normalized in {"(no matches found)", "(no matching entries found)", "no matches found"}

    def _sanitize_admin_log_line(self, line: str) -> str:
        cleaned = line.strip()
        cleaned = re.sub(r"^/var/log/[^:]+:\d+:", "", cleaned)
        cleaned = re.sub(r"^/var/log/[^:]+:", "", cleaned)
        cleaned = re.sub(r"^\d+:", "", cleaned)
        cleaned = re.sub(r"^(?:[A-Z][a-z]{2}\s+\d{1,2}\s+\d{2}:\d{2}:\d{2}\s+\S+\s+)", "", cleaned)
        return cleaned.strip()

    def _is_confirmed_failed_login_line(self, line: str) -> bool:
        normalized = line.lower()
        return any(
            token in normalized
            for token in (
                "failed password",
                "invalid user",
                "authentication failure",
                "pam_unix",
                "auth failed",
            )
        )

    def _is_auth_attempt_line(self, line: str) -> bool:
        normalized = line.lower()
        return any(
            token in normalized
            for token in (
                "connection closed by authenticating user",
                "disconnected from authenticating user",
                "accepted password",
                "accepted publickey",
                "preauth",
            )
        )

    def _classify_admin_log_lines(self, lines: list[str]) -> dict[str, int]:
        categories = {
            "ssh/auth": 0,
            "mail/postfix": 0,
            "mail/spamd": 0,
            "mail/dovecot": 0,
            "mail/other": 0,
            "service/system": 0,
            "web/kernel": 0,
            "cron": 0,
            "audit/security": 0,
            "other": 0,
        }
        for line in lines:
            normalized = line.lower()
            if any(token in normalized for token in ("sshd", "ssh", "authenticating user", "invalid user", "failed password", "preauth")):
                categories["ssh/auth"] += 1
            elif any(token in normalized for token in ("postfix", "smtpd", "smtp", "bounce", "delivery", "maildrop", "cleanup")):
                categories["mail/postfix"] += 1
            elif any(token in normalized for token in ("spamd", "spamassassin", "clamav", "amavis")):
                categories["mail/spamd"] += 1
            elif any(token in normalized for token in ("dovecot", "imap", "pop3", "lmtp", "auth-worker")):
                categories["mail/dovecot"] += 1
            elif "maillog" in normalized:
                categories["mail/other"] += 1
            elif any(token in normalized for token in ("cron", "systemd", "service", "restart", "failed to start", "cleanup stage failed")):
                categories["service/system"] += 1
            elif any(token in normalized for token in ("httpd", "apache", "php", "opcache", "kernel", "segfault")):
                categories["web/kernel"] += 1
            elif "audit" in normalized:
                categories["audit/security"] += 1
            else:
                categories["other"] += 1
        return categories

    def _categorize_admin_log_line(self, line: str) -> str:
        normalized = line.lower()
        if any(token in normalized for token in ("sshd", "ssh", "authenticating user", "invalid user", "failed password", "preauth")):
            return "ssh/auth"
        if any(token in normalized for token in ("postfix", "smtpd", "smtp", "bounce", "delivery", "maildrop", "cleanup")):
            return "mail/postfix"
        if any(token in normalized for token in ("spamd", "spamassassin", "clamav", "amavis")):
            return "mail/spamd"
        if any(token in normalized for token in ("dovecot", "imap", "pop3", "lmtp", "auth-worker")):
            return "mail/dovecot"
        if "maillog" in normalized:
            return "mail/other"
        if any(token in normalized for token in ("cron", "systemd", "service", "restart", "failed to start", "cleanup stage failed")):
            return "service/system"
        if any(token in normalized for token in ("httpd", "apache", "php", "opcache", "kernel", "segfault")):
            return "web/kernel"
        if "audit" in normalized:
            return "audit/security"
        return "other"

    def _collect_admin_log_examples(self, lines: list[str], limit_per_category: int = 2) -> dict[str, list[str]]:
        examples: dict[str, list[str]] = {}
        for line in lines:
            category = self._categorize_admin_log_line(line)
            bucket = examples.setdefault(category, [])
            if len(bucket) < limit_per_category:
                bucket.append(line)
        return examples

    def _admin_log_category_label(self, category: str) -> str:
        label_map = {
            "ssh/auth": "SSH auth activity",
            "mail/postfix": "Postfix mail activity",
            "mail/spamd": "Spam filter activity",
            "mail/dovecot": "Dovecot mail activity",
            "mail/other": "mail activity",
            "service/system": "service/system errors",
            "web/kernel": "web/kernel issues",
            "cron": "cron activity",
            "audit/security": "audit/security events",
            "other": "miscellaneous events",
        }
        return label_map.get(category, category)

    def _summarize_admin_categories(self, counts: dict[str, int]) -> tuple[str, list[str], list[str]]:
        positives = [(name, count) for name, count in counts.items() if count > 0]
        if not positives:
            return "other", ["No dominant signal"], []

        positives.sort(key=lambda item: item[1], reverse=True)
        top_name, top_count = positives[0]
        top_label = self._admin_log_category_label(top_name)
        if top_name.startswith("mail/"):
            top_line = f"Top signal: mail delivery activity -> {top_label} ({top_count} line(s))"
        else:
            top_line = f"Top signal: {top_label} ({top_count} line(s))"
        detail_lines = [f"- {self._admin_log_category_label(name)}: {count} line(s)" for name, count in positives[:3]]
        return top_line, detail_lines, [name for name, _ in positives[:3]]

    def _format_admin_log_result(self, message: str, raw_output: str, *, detail_mode: bool = False) -> str:
        lines = [self._sanitize_admin_log_line(line) for line in (raw_output or "").splitlines() if line.strip()]
        lines = [line for line in lines if line and "sudo[" not in line.lower() and "command continued" not in line.lower()]
        if not lines:
            return "No matching entries found in the inspected logs."

        normalized_message = " ".join((message or "").strip().lower().split())
        failed_login = self._is_failed_login_request(message)
        login_attempt = self._is_login_attempt_request(message)
        title = "Log Findings"
        if failed_login and not login_attempt:
            title = "Failed Login Findings"
            lines = [line for line in lines if self._is_confirmed_failed_login_line(line)]
            if not lines:
                return "Failed Login Findings:\nNo confirmed failed login lines were found."
        elif login_attempt:
            title = "Login Attempt Findings"
            lines = [line for line in lines if self._is_auth_attempt_line(line)]
            if not lines:
                return "Login Attempt Findings:\nNo login attempts found."

        bullet_limit = 2
        bullet_lines = [f"- {line}" for line in lines[:bullet_limit]]

        summary_bits: list[str] = []
        if failed_login and not login_attempt:
            summary_bits.append("Confirmed failed login lines were found.")
        if login_attempt or "authenticating user" in normalized_message or "preauth" in normalized_message:
            summary_bits.append("Preauth/authentication activity was observed.")
        if not summary_bits:
            summary_bits.append("Relevant log entries were found.")

        if not detail_mode and not (failed_login or login_attempt):
            counts = self._classify_admin_log_lines(lines)
            top_line, category_bits, top_categories = self._summarize_admin_categories(counts)
            examples = self._collect_admin_log_examples(lines)
            example_lines: list[str] = []
            for category in top_categories:
                samples = examples.get(category, [])
                if not samples:
                    continue
                example_lines.append(f"- {self._admin_log_category_label(category)}:")
                example_lines.append(f"  - {samples[0]}")
            return "\n".join(
                [
                    "Admin Log Summary:",
                    *summary_bits,
                    top_line,
                    "",
                    "Signals:",
                    *category_bits,
                    "",
                    "Examples:",
                    *(example_lines if example_lines else ["- No representative examples available"]),
                ]
            )

        output_lines = [
            f"{title}:",
            *summary_bits,
            "Evidence:" if detail_mode or failed_login or login_attempt else "Highlights:",
            *bullet_lines,
        ]
        return "\n".join(output_lines)

    def _admin_log_search_args(
        self,
        message: str,
        *,
        login_attempts: bool = False,
        all_logs: bool = False,
    ) -> dict[str, Any]:
        """
        Build a conservative log-search query for common auth/login incidents.

        The paths are narrowed when the user mentions a specific log family;
        otherwise the tool's default admin-log set is used.
        """
        normalized = " ".join((message or "").strip().lower().split())
        if all_logs:
            pattern = (
                r"Failed password|Invalid user|authentication failure|PAM: Authentication failure|"
                r"pam_unix.*sshd:auth|sshd.*failed|Connection closed by authenticating user|"
                r"Disconnected from authenticating user|Error|failed|failure|denied|reject|"
                r"bounce|status=deferred|status=bounced|refused|quota exceeded|out of memory|"
                r"oom-kill|panic|segfault"
            )
        elif login_attempts:
            pattern = r"Failed password|Invalid user|authentication failure|PAM: Authentication failure|pam_unix.*sshd:auth|sshd.*failed|Connection closed by authenticating user|Disconnected from authenticating user|Accepted password|Accepted publickey"
        else:
            pattern = r"Failed password|Invalid user|authentication failure|PAM: Authentication failure|pam_unix.*sshd:auth|sshd.*failed"
        paths: list[str] = []

        if "maillog" in normalized or "mail log" in normalized:
            paths.append("/var/log/maillog")
        elif "secure" in normalized or "ssh" in normalized:
            paths.append("/var/log/secure")
        elif "messages" in normalized:
            paths.extend(["/var/log/messages", "/var/log/secure"])
        elif "cron" in normalized:
            paths.append("/var/log/cron")
        elif "httpd" in normalized or "apache" in normalized:
            paths.append("/var/log/httpd/error_log")
        elif "dovecot" in normalized:
            paths.append("/var/log/maillog")
        elif "audit" in normalized:
            paths.append("/var/log/audit/audit.log")

        args: dict[str, Any] = {
            "pattern": pattern,
            "context_lines": 0,
            "max_matches": 12 if all_logs else 8,
            "ignore_case": True,
        }
        if paths:
            args["paths"] = list(dict.fromkeys(paths))
        return args

    async def run(
        self,
        message: str,
        session_id: str,
        run_as: str = "blueonyx_ai",
    ) -> AsyncGenerator[dict[str, Any], None]:
        """Run the agent loop for a single user message.

        Yields event dicts with keys:
            type: str -- "delta", "tool_call", "tool_result", "confirmation_required",
                         "done", "error"
            Plus type-specific data fields.
        """
        try:
            # 1. Load session and add user message
            session = await self.session_manager.get(session_id)
            messages = session.get("messages", [])

            # Add system prompt if this is a fresh session
            if not messages:
                messages.append({"role": "system", "content": self.system_prompt})
            elif messages[0].get("role") == "system":
                messages[0]["content"] = self.system_prompt
            else:
                messages.insert(0, {"role": "system", "content": self.system_prompt})

            # Add the user message
            messages.append({"role": "user", "content": message})
            self._last_user_message = message
            await self.session_manager.save(session_id, session)

            # Tool definitions for the LLM
            tool_defs = self.tool_executor.get_tool_definitions(self.tools_enabled, self.allowed_tool_categories)
            if not self.allow_generic_privileged_command:
                tool_defs = [tool for tool in tool_defs if tool.name != "run_privileged_command"]
            # Apply profile-based tool whitelist for restricted models
            if self._tool_whitelist is not None:
                tool_defs = [tool for tool in tool_defs if tool.name in self._tool_whitelist]

            knowledge_context_message: str | None = None

            # Deterministic shortcut for exact uname output requests.
            # Do this before the LLM sees the message so we do not depend on the model
            # choosing the correct tool path for a command that must be exact.
            if self._is_uname_request(message):
                logger.info("Short-circuiting exact uname request to system_uname tool")
                result: ToolResult = await self._execute_tool("system_uname", {}, run_as)
                if result.success:
                    await self.session_manager.add_message(session_id, "assistant", result.output)
                    yield {"type": "delta", "content": result.output}
                    yield {"type": "done", "data": {}}
                    return
                yield {"type": "error", "message": result.error or "Failed to execute uname"}
                yield {"type": "done", "data": {}}
                return

            if self._is_disk_health_request(message):
                logger.info("Short-circuiting disk health request to summarized df output")
                try:
                    output = await self._get_disk_health_output()
                    await self.session_manager.add_message(session_id, "assistant", output)
                    yield {"type": "delta", "content": output}
                    yield {"type": "done", "data": {}}
                    return
                except Exception as exc:
                    yield {"type": "error", "message": str(exc) or "Failed to execute disk health query"}
                    yield {"type": "done", "data": {}}
                    return

            if self._is_incident_timeline_request(message):
                logger.info("Short-circuiting incident timeline request to incident_timeline tool")
                result = await self._execute_tool("incident_timeline", self._timeline_args(message), "root")
                if result.success:
                    output = (result.output or "").strip()
                    await self.session_manager.add_message(session_id, "assistant", output)
                    yield {"type": "delta", "content": output}
                    yield {"type": "done", "data": {}}
                    return
                yield {"type": "error", "message": result.error or "Failed to build incident timeline"}
                yield {"type": "done", "data": {}}
                return

            if self._is_vsite_creation_request(message):
                logger.info("Short-circuiting Vsite creation question to capability explanation")
                output = self._get_vsite_creation_explanation()
                await self.session_manager.add_message(session_id, "assistant", output)
                yield {"type": "delta", "content": output}
                yield {"type": "done", "data": {}}
                return

            if self._is_webroot_integrity_request(message):
                logger.info("Short-circuiting webroot integrity question to deterministic scan")
                target_path = self._extract_webroot_path(message)
                if not target_path:
                    if self._contains_explicit_path(message):
                        yield {"type": "error", "message": "Only /home/sites/<fqdn>/wwwroot/web/ or /home/.sites/<site>/wwwroot/web/ paths are supported for webroot integrity checks"}
                        yield {"type": "done", "data": {}}
                        return
                    if self._is_webroot_followup_request(message) and self.model_profile in {"restricted", "guided"}:
                        target_path = await self._get_last_webroot_path(session_id)
                    if not target_path:
                        # No specific Vsite mentioned — scan all Vsites
                        try:
                            output = await self._scan_all_vsites()
                            await self.session_manager.add_message(session_id, "assistant", output)
                            yield {"type": "delta", "content": output}
                            yield {"type": "done", "data": {}}
                            return
                        except Exception as exc:
                            yield {"type": "error", "message": str(exc) or "Failed to scan Vsites"}
                            yield {"type": "done", "data": {}}
                            return
                try:
                    await self._remember_webroot_path(session_id, target_path)
                    output = await self._get_webroot_integrity_output(target_path)
                    await self.session_manager.add_message(session_id, "assistant", output)
                    yield {"type": "delta", "content": output}
                    yield {"type": "done", "data": {}}
                    return
                except Exception as exc:
                    yield {"type": "error", "message": str(exc) or "Failed to inspect webroot integrity"}
                    yield {"type": "done", "data": {}}
                    return

            if self._is_webroot_forensic_request(message):
                logger.info("Handling webroot forensic request")
                target_path = self._extract_webroot_path(message)
                if not target_path and self._contains_explicit_path(message):
                    yield {"type": "error", "message": "Only /home/sites/<fqdn>/wwwroot/web/ or /home/.sites/<site>/wwwroot/web/ paths are supported for webroot integrity checks"}
                    yield {"type": "done", "data": {}}
                    return
                if not target_path and self._is_webroot_followup_request(message) and self.model_profile in {"restricted", "guided"}:
                    target_path = await self._get_last_webroot_path(session_id)
                if target_path and self.model_profile in {"restricted", "guided"}:
                    try:
                        await self._remember_webroot_path(session_id, target_path)
                        output = await self._get_webroot_integrity_output(target_path)
                        await self.session_manager.add_message(session_id, "assistant", output)
                        yield {"type": "delta", "content": output}
                        yield {"type": "done", "data": {}}
                        return
                    except Exception as exc:
                        yield {"type": "error", "message": str(exc) or "Failed to inspect webroot integrity"}
                        yield {"type": "done", "data": {}}
                        return
                if self.model_profile in {"restricted", "guided"}:
                    # No specific Vsite mentioned — scan all Vsites
                    try:
                        output = await self._scan_all_vsites()
                        await self.session_manager.add_message(session_id, "assistant", output)
                        yield {"type": "delta", "content": output}
                        yield {"type": "done", "data": {}}
                        return
                    except Exception as exc:
                        yield {"type": "error", "message": str(exc) or "Failed to scan Vsites"}
                        yield {"type": "done", "data": {}}
                        return

            if self._is_gui_500_request(message):
                logger.info("Short-circuiting GUI 500 request to development-mode advice")
                output = self._get_gui_500_advice()
                await self.session_manager.add_message(session_id, "assistant", output)
                yield {"type": "delta", "content": output}
                yield {"type": "done", "data": {}}
                return

            if self._is_cced_transaction_request(message):
                logger.info("Short-circuiting GUI/BlueOnyx failure question to CCEd transaction log search")
                try:
                    output = await self._get_cced_transaction_failure_output(message)
                    await self.session_manager.add_message(session_id, "assistant", output)
                    yield {"type": "delta", "content": output}
                    yield {"type": "done", "data": {}}
                    return
                except Exception as exc:
                    yield {"type": "error", "message": str(exc) or "Failed to inspect CCEd transaction logs"}
                    yield {"type": "done", "data": {}}
                    return

            if self._is_account_disk_usage_request(message):
                logger.info("Short-circuiting account disk usage request to live quota output")
                try:
                    args = self._extract_account_disk_usage_args(message)
                    output = await self._get_account_disk_usage_output(args["kind"], args["target"])
                    await self.session_manager.add_message(session_id, "assistant", output)
                    yield {"type": "delta", "content": output}
                    yield {"type": "done", "data": {}}
                    return
                except Exception as exc:
                    yield {"type": "error", "message": str(exc) or "Failed to execute account disk usage query"}
                    yield {"type": "done", "data": {}}
                    return

            if self._is_support_channels_request(message):
                logger.info("Short-circuiting support channels request to canonical support channel list")
                output = self._get_support_channels_output()
                await self.session_manager.add_message(session_id, "assistant", output)
                yield {"type": "delta", "content": output}
                yield {"type": "done", "data": {}}
                return

            if self._is_migration_request(message):
                logger.info("Short-circuiting migration request to canonical migration guidance")
                output = self._get_migration_help_output()
                await self.session_manager.add_message(session_id, "assistant", output)
                yield {"type": "delta", "content": output}
                yield {"type": "done", "data": {}}
                return

            if self._is_email_autoconfig_request(message):
                logger.info("Short-circuiting email client setup question to Email Autoconfiguration knowledge")
                output = self._format_email_autoconfig_output(message)
                await self.session_manager.add_message(session_id, "assistant", output)
                yield {"type": "delta", "content": output}
                yield {"type": "done", "data": {}}
                return

            if self._is_blueonyx_knowledge_request(message):
                logger.info("Short-circuiting BlueOnyx support question to local knowledge search")
                try:
                    result = await self._execute_tool(
                        "search_blueonyx_knowledge",
                        {"query": message},
                        "root",
                    )
                    if result.success:
                        output = (result.output or "").strip()
                        if output and not self._is_no_matches_output(output):
                            knowledge_context_message = (
                                "BlueOnyx knowledge context for this question. "
                                "Use it as grounding only. Do not quote it verbatim, and do not dump registry output.\n\n"
                                f"{output}\n\n"
                                "Answer the user's question directly and practically."
                            )
                except Exception as exc:
                    logger.warning("BlueOnyx knowledge search failed, falling back to LLM: %s", exc)

            if knowledge_context_message:
                if messages and messages[0].get("role") == "system":
                    messages.insert(1, {"role": "system", "content": knowledge_context_message})
                else:
                    messages.insert(0, {"role": "system", "content": knowledge_context_message})

            if self._is_disk_space_request(message):
                logger.info("Short-circuiting disk space request to direct df output")
                try:
                    output = await self._get_disk_space_output()
                    await self.session_manager.add_message(session_id, "assistant", output)
                    yield {"type": "delta", "content": output}
                    yield {"type": "done", "data": {}}
                    return
                except Exception as exc:
                    yield {"type": "error", "message": str(exc) or "Failed to execute disk space query"}
                    yield {"type": "done", "data": {}}
                    return

            if self._is_network_usage_request(message):
                logger.info("Short-circuiting network usage request to system_network_status tool")
                result = await self._execute_tool("system_network_status", {}, "root")
                if result.success:
                    output = (result.output or "").strip()
                    await self.session_manager.add_message(session_id, "assistant", output)
                    yield {"type": "delta", "content": output}
                    yield {"type": "done", "data": {}}
                    return
                yield {"type": "error", "message": result.error or "Failed to collect network status"}
                yield {"type": "done", "data": {}}
                return

            if self._is_server_health_request(message):
                logger.info("Short-circuiting server health request to server_health_summary tool")
                result = await self._execute_tool("server_health_summary", {}, "root")
                if result.success:
                    output = (result.output or "").strip()
                    await self.session_manager.add_message(session_id, "assistant", output)
                    yield {"type": "delta", "content": output}
                    yield {"type": "done", "data": {}}
                    return
                yield {"type": "error", "message": result.error or "Failed to collect server health summary"}
                yield {"type": "done", "data": {}}
                return

            if self._is_ssl_health_request(message):
                logger.info("Short-circuiting SSL health request to ssl_health tool")
                result = await self._execute_tool("ssl_health", {}, "root")
                if result.success:
                    output = (result.output or "").strip()
                    await self.session_manager.add_message(session_id, "assistant", output)
                    yield {"type": "delta", "content": output}
                    yield {"type": "done", "data": {}}
                    return
                yield {"type": "error", "message": result.error or "Failed to collect SSL health"}
                yield {"type": "done", "data": {}}
                return

            if self._is_php_fpm_health_request(message):
                logger.info("Short-circuiting PHP-FPM health request to php_fpm_health tool")
                result = await self._execute_tool("php_fpm_health", {}, "root")
                if result.success:
                    output = (result.output or "").strip()
                    await self.session_manager.add_message(session_id, "assistant", output)
                    yield {"type": "delta", "content": output}
                    yield {"type": "done", "data": {}}
                    return
                yield {"type": "error", "message": result.error or "Failed to collect PHP-FPM health"}
                yield {"type": "done", "data": {}}
                return

            if self._is_site_health_request(message):
                logger.info("Short-circuiting site health request to site_health_evidence tool")
                target = await self._extract_site_health_target(message, session_id)
                if not target:
                    yield {"type": "error", "message": "Please specify a Vsite FQDN or internal site name."}
                    yield {"type": "done", "data": {}}
                    return
                try:
                    await self._remember_site_health_target(session_id, target)
                    result = await self._execute_tool("site_health_evidence", {"site": target}, "root")
                except Exception as exc:
                    yield {"type": "error", "message": str(exc) or "Failed to collect site health evidence"}
                    yield {"type": "done", "data": {}}
                    return
                if result.success:
                    output = (result.output or "").strip()
                    await self.session_manager.add_message(session_id, "assistant", output)
                    yield {"type": "delta", "content": output}
                    yield {"type": "done", "data": {}}
                    return
                yield {"type": "error", "message": result.error or "Failed to collect site health evidence"}
                yield {"type": "done", "data": {}}
                return

            if self._is_vsite_inventory_request(message):
                logger.info("Short-circuiting Vsite inventory request to list_vsites tool")
                result = await self._execute_tool("list_vsites", self._vsite_inventory_args(message), "root")
                if result.success:
                    output = (result.output or "").strip()
                    await self.session_manager.add_message(session_id, "assistant", output)
                    yield {"type": "delta", "content": output}
                    yield {"type": "done", "data": {}}
                    return
                yield {"type": "error", "message": result.error or "Failed to list Vsites"}
                yield {"type": "done", "data": {}}
                return

            if self._is_web_owner_request(message):
                logger.info("Short-circuiting web owner request to web_owner_health tool")
                result = await self._execute_tool("web_owner_health", {}, "root")
                if result.success:
                    output = (result.output or "").strip()
                    await self.session_manager.add_message(session_id, "assistant", output)
                    yield {"type": "delta", "content": output}
                    yield {"type": "done", "data": {}}
                    return
                yield {"type": "error", "message": result.error or "Failed to collect web owner health"}
                yield {"type": "done", "data": {}}
                return

            # Deterministic shortcut for spam/abuse investigation.
            # Identifies which account is blasting spam, top offenders, suspicious IPs.
            # Must come before mail_stats / mail_health / email_problem shortcuts
            # so "who is sending spam" is not swallowed by simpler matchers.
            if self._is_spam_abuse_request(message):
                logger.info("Short-circuiting spam/abuse investigation to spam_abuse tool")
                result = await self._execute_tool("spam_abuse", self._spam_abuse_args(message), "root")
                if result.success:
                    output = (result.output or "").strip()
                    await self.session_manager.add_message(session_id, "assistant", output)
                    yield {"type": "delta", "content": output}
                    yield {"type": "done", "data": {}}
                    return
                yield {"type": "error", "message": result.error or "Failed to collect spam abuse report"}
                yield {"type": "done", "data": {}}
                return

            # Deterministic shortcut for email problem diagnosis.
            # Combines mail_health + search_admin_logs + service_status for the
            # "Why can't jdoe send emails?" type of question.  Must come before
            # mail_stats / mail_health shortcuts so problem questions are not
            # swallowed by the simpler matchers.
            if self._is_email_problem_request(message):
                logger.info("Short-circuiting email problem diagnosis")
                try:
                    output = await self._diagnose_email_problem(message)
                    await self.session_manager.add_message(session_id, "assistant", output)
                    yield {"type": "delta", "content": output}
                    yield {"type": "done", "data": {}}
                    return
                except Exception as exc:
                    yield {"type": "error", "message": str(exc) or "Failed to diagnose email problem"}
                    yield {"type": "done", "data": {}}
                    return

            # Deterministic shortcut for mail statistics requests.
            if self._is_mail_stats_request(message):
                logger.info("Short-circuiting mail stats request to mail_stats tool")
                result = await self._execute_tool("mail_stats", self._mail_stats_args(message), "root")
                if result.success:
                    output = (result.output or "").strip()
                    await self.session_manager.add_message(session_id, "assistant", output)
                    yield {"type": "delta", "content": output}
                    yield {"type": "done", "data": {}}
                    return
                yield {"type": "error", "message": result.error or "Failed to collect mail statistics"}
                yield {"type": "done", "data": {}}
                return

            # Deterministic shortcut for mail-health requests.
            if self._is_mail_health_request(message):
                logger.info("Short-circuiting mail health request to mail_health tool")
                result = await self._execute_tool("mail_health", self._mail_health_args(message), "root")
                if result.success:
                    output = (result.output or "").strip()
                    await self.session_manager.add_message(session_id, "assistant", output)
                    yield {"type": "delta", "content": output}
                    yield {"type": "done", "data": {}}
                    return
                yield {"type": "error", "message": result.error or "Failed to collect mail health"}
                yield {"type": "done", "data": {}}
                return

            if self._is_general_suspicion_request(message):
                logger.info("Short-circuiting general suspicion request to broad deterministic checks")
                try:
                    output = await self._get_general_suspicion_output(message)
                    await self.session_manager.add_message(session_id, "assistant", output)
                    yield {"type": "delta", "content": output}
                    yield {"type": "done", "data": {}}
                    return
                except Exception as exc:
                    yield {"type": "error", "message": str(exc) or "Failed to run broad suspicion check"}
                    yield {"type": "done", "data": {}}
                    return

            # Deterministic shortcut for admin log investigations.
            # These are evidence-gathering tasks, so we should search the logs directly
            # instead of letting the model guess at symptoms or conclusions.
            if self._is_admin_log_request(message):
                logger.info("Short-circuiting admin log request to search_admin_logs tool")
                all_logs = self._is_all_logs_request(message)
                result = await self._execute_tool(
                    "search_admin_logs",
                    self._admin_log_search_args(
                        message,
                        login_attempts=self._is_login_attempt_request(message),
                        all_logs=all_logs,
                    ),
                    "root",
                )
                if result.success:
                    output = (result.output or "").strip()
                    if self._is_no_matches_output(output):
                        if self._is_failed_login_request(message):
                            output = "No failed login entries found."
                        elif self._is_login_attempt_request(message):
                            output = "No login attempts found."
                        else:
                            output = "No matching entries found in the inspected logs."
                    else:
                        output = self._format_admin_log_result(
                            message,
                            output,
                            detail_mode=self._is_detailed_log_request(message),
                        )
                    output = await self._maybe_append_support_hint(session_id, message, output)
                    await self.session_manager.add_message(session_id, "assistant", output)
                    yield {"type": "delta", "content": output}
                    yield {"type": "done", "data": {}}
                    return
                yield {"type": "error", "message": result.error or "Failed to search admin logs"}
                yield {"type": "done", "data": {}}
                return

            # 2-6. Agent loop
            max_iterations = self.max_iterations  # Profile-dependent safety limit
            for iteration in range(max_iterations):
                logger.debug("Agent iteration %d for session %s", iteration, session_id)

                # For restricted profiles, add a capability boundary reminder
                # injected into the LLM context only (not stored in session history).
                # This prevents the model from attempting multi-step investigations
                # or complex reasoning beyond its capability.
                _restricted_reminder = ""
                if self.model_profile == "restricted":
                    _restricted_reminder = (
                        "[RESTRICTED MODE: You have limited tools. "
                        "Answer directly from available information. "
                        "If you cannot answer confidently, say so and suggest BlueOnyx support. "
                        "Do NOT attempt multi-step investigations or combine tools creatively.]"
                    )

                # Send to LLM
                tool_calls_this_round: list[dict] = []
                full_response = ""
                llm_messages = self._prepare_messages_for_llm(messages)
                # Inject restricted-mode reminder by appending to the system prompt
                # in the LLM context only (not stored in session history).
                # This avoids breaking role alternation and _prepare_messages_for_llm.
                if _restricted_reminder and llm_messages and llm_messages[0].get("role") == "system":
                    llm_messages[0] = {
                        "role": "system",
                        "content": llm_messages[0]["content"] + "\n\n" + _restricted_reminder,
                    }
                async for event in self.provider.chat(
                    messages=llm_messages,
                    tools=[td.to_dict() for td in tool_defs] if tool_defs else None,
                    stream=True,
                ):
                    if event["type"] == "delta":
                        full_response += event["content"]
                        yield {"type": "delta", "content": event["content"]}

                    elif event["type"] == "tool_call":
                        tool_calls_this_round.append(event)
                        yield {
                            "type": "tool_call",
                            "id": event["id"],
                            "name": event["name"],
                            "arguments": event["arguments"],
                            "requires_confirmation": event["name"] in WRITE_TOOLS,
                        }

                    elif event["type"] == "error":
                        await self.session_manager.add_message(
                            session_id, "assistant", full_response or "Error occurred"
                        )
                        yield {"type": "error", "message": event["message"]}
                        yield {"type": "done", "data": {}}
                        return

                # If there were tool calls, process them and loop
                if tool_calls_this_round:
                    # Add assistant response with tool calls to history
                    assistant_msg = {"role": "assistant", "content": full_response or None}
                    assistant_msg["tool_calls"] = []
                    for tc in tool_calls_this_round:
                        assistant_msg["tool_calls"].append({
                            "id": tc["id"],
                            "type": "function",
                            "function": {
                                "name": tc["name"],
                                "arguments": json.dumps(tc["arguments"]),
                            },
                        })
                    messages.append(assistant_msg)

                    # Process each tool call
                    for tc in tool_calls_this_round:
                        tool_name = tc["name"]
                        tool_args = tc["arguments"]
                        tool_id = tc["id"]

                        # Write tools require confirmation
                        if tool_name in WRITE_TOOLS:
                            yield {
                                "type": "confirmation_required",
                                "tool_call_id": tool_id,
                                "tool": tool_name,
                                "args": tool_args,
                                "reason": f"This operation modifies system state: {tool_name}",
                            }
                            # Save pending confirmation to session
                            await self.session_manager.set_pending_confirmation(
                                session_id, tool_id, tool_name, tool_args,
                                f"Write operation: {tool_name}",
                            )
                            # Also add a tool result placeholder explaining it's pending
                            tool_result_msg = {
                                "role": "tool",
                                "tool_call_id": tool_id,
                                "content": json.dumps({
                                    "status": "pending_confirmation",
                                    "message": f"Operation {tool_name} requires user confirmation. Waiting for approval.",
                                }),
                            }
                            messages.append(tool_result_msg)
                            # Yield the tool result so the frontend knows
                            yield {
                                "type": "tool_result",
                                "id": tool_id,
                                "result": {
                                    "status": "pending_confirmation",
                                    "message": "Waiting for user confirmation",
                                },
                            }
                        else:
                            # Read tool -- execute immediately
                            logger.info(
                                "Executing read tool: %s with args: %s",
                                tool_name,
                                tool_args,
                            )
                            result: ToolResult = await self._execute_tool(
                                tool_name, tool_args, run_as,
                            )

                            result_content = {
                                "status": "ok" if result.success else "error",
                                "output": result.output,
                            }
                            if result.error:
                                result_content["error"] = result.error

                            # Add tool result to messages
                            messages.append({
                                "role": "tool",
                                "tool_call_id": tool_id,
                                "content": json.dumps(result_content),
                            })

                            yield {
                                "type": "tool_result",
                                "id": tool_id,
                                "result": result_content,
                            }

                    # Save conversation state without clobbering concurrent
                    # fields like pending_confirmation that were written after
                    # the session snapshot was originally loaded.
                    session_to_save = await self.session_manager.get(session_id)
                    session_to_save["messages"] = messages
                    await self.session_manager.save(session_id, session_to_save)

                    # Continue loop -- LLM will process tool results
                    continue

                # No tool calls -- this is the final answer
                if full_response:
                    full_response = await self._maybe_append_support_hint(session_id, message, full_response)
                    await self.session_manager.add_message(
                        session_id, "assistant", full_response,
                    )
                else:
                    # Edge case: empty response
                    await self.session_manager.add_message(
                        session_id, "assistant",
                        "I've completed the analysis. No further actions needed.",
                    )

                yield {"type": "done", "data": {}}
                return

            # If we hit max_iterations without a final answer
            await self.session_manager.add_message(
                session_id, "assistant",
                "I've reached the maximum number of operations for this request. "
                "Please rephrase or narrow your request.",
            )
            yield {"type": "done", "data": {"note": "max_iterations_reached"}}

        except Exception as e:
            logger.exception("Agent error for session %s: %s", session_id, e)
            yield {"type": "error", "message": f"Agent error: {str(e)}"}
            yield {"type": "done", "data": {}}
