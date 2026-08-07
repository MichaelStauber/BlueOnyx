#!/usr/bin/env python3
"""
ai_service.py -- BlueOnyx AI Agent FastAPI Service.

Copyright (c) 2026 Michael Stauber, SOLARSPEED.NET
Copyright (c) 2026 Team BlueOnyx, BLUEONYX.IT
All Rights Reserved.

Endpoints:
    GET  /health          -- systemd readiness probe
    POST /chat            -- streaming chat (SSE), args: message, session_id, run_as
    POST /function        -- JSON tool execution, args: function, args, run_as

Lifecycle (socket activation + idle timeout):
    - systemd socket on 127.0.0.1:1972 starts us on first connection
    - On each request we record activity and reset the idle timer
    - If no activity for idle_timeout minutes, we exit(0) cleanly
      -- next connection wakes us again via socket activation
"""

from __future__ import annotations

import asyncio
import hmac
import json
import logging
import os
import re
import signal
import sys
import time
import uuid
import warnings
from pathlib import Path
from typing import Any, AsyncGenerator

import uvicorn
from fastapi import FastAPI, Request, HTTPException
from fastapi.responses import HTMLResponse, StreamingResponse

# ---------------------------------------------------------------------------
# Runtime environment
# ---------------------------------------------------------------------------
RUNTIME_HOME = Path("/home/ai")
RUNTIME_CACHE_DIR = RUNTIME_HOME / ".cache"
RUNTIME_TIKTOKEN_CACHE_DIR = RUNTIME_CACHE_DIR / "tiktoken"

os.environ.setdefault("HOME", str(RUNTIME_HOME))
os.environ.setdefault("XDG_CACHE_HOME", str(RUNTIME_CACHE_DIR))
os.environ.setdefault("TIKTOKEN_CACHE_DIR", str(RUNTIME_TIKTOKEN_CACHE_DIR))
os.environ.setdefault("CUSTOM_TIKTOKEN_CACHE_DIR", str(RUNTIME_TIKTOKEN_CACHE_DIR))

for runtime_dir in (RUNTIME_HOME, RUNTIME_CACHE_DIR, RUNTIME_TIKTOKEN_CACHE_DIR):
    runtime_dir.mkdir(parents=True, exist_ok=True)

# LiteLLM 1.59.0 imports a Pydantic v1-style config from an optional
# integration module, which emits a harmless warning under Pydantic v2.
warnings.filterwarnings(
    "ignore",
    message=r"Valid config keys have changed in V2:\s*\n\* 'fields' has been removed",
    category=UserWarning,
    module=r"pydantic\._internal\._config",
)

# Local packages
from sausalito_ai.providers.external_provider import ExternalProvider
from sausalito_ai.model_caps import get_model_capabilities, ModelCapabilityStore, PROBE_CONFIDENCE_THRESHOLD
from sausalito_ai.tools.base import ToolExecutor
from sausalito_ai.agent.session import SessionManager
from sausalito_ai.agent.agent import Agent
from sausalito_ai.audit_log import AuditLogger

# ---------------------------------------------------------------------------
# Logging
# ---------------------------------------------------------------------------
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(name)-30s %(levelname)-8s %(message)s",
    stream=sys.stdout,
)
logger = logging.getLogger("sausalito_ai.service")
logging.getLogger("LiteLLM").setLevel(logging.WARNING)
logging.getLogger("httpx").setLevel(logging.WARNING)
logging.getLogger("httpcore").setLevel(logging.WARNING)
logging.getLogger("uvicorn.access").setLevel(logging.WARNING)

# ---------------------------------------------------------------------------
# Paths
# ---------------------------------------------------------------------------
SESSION_DIR = Path("/home/ai/sessions")
LOG_DIR = Path("/home/ai/logs")
SESSION_DIR.mkdir(parents=True, exist_ok=True)
LOG_DIR.mkdir(parents=True, exist_ok=True)

# ---------------------------------------------------------------------------
# CCEd config loader
# ---------------------------------------------------------------------------
def load_cce_config() -> dict[str, Any]:
    """Load System.AI config from JSON file written by CCE handler/constructor.

    Reads /home/ai/ai_config.json which is updated by:
    - CCE handler when AI settings are saved in GUI
    - CCE constructor when CCEd starts

    Returns a dict with keys:
        enabled (bool), provider (str), openai_api_key (str),
        openrouter_api_key (str), ollama_api_key (str), custom_api_key (str),
        service_api_key (str),
        default_model (str), custom_endpoint (str), idle_timeout (int),
        system_prompt (str), tools_enabled (bool),
        read_only_tools_enabled (bool), diagnostics_tools_enabled (bool),
        actions_tools_enabled (bool),
        allow_generic_privileged_command (bool), priv_tools_available (list[str])

    On error returns defaults (provider=local, enabled=False).
    """
    import json
    from pathlib import Path

    config_file = Path("/home/ai/ai_config.json")
    
    defaults: dict[str, Any] = {
        "enabled": False,
        "provider": "local",
        "openai_api_key": "",
        "openrouter_api_key": "",
        "ollama_api_key": "",
        "custom_api_key": "",
        "service_api_key": "",
        "default_model": "",
        "custom_endpoint": "",
        "idle_timeout": 5,
        "system_prompt": "",
        "tools_enabled": True,
        "read_only_tools_enabled": True,
        "diagnostics_tools_enabled": True,
        "actions_tools_enabled": True,
        "allow_generic_privileged_command": False,
        "priv_tools_available": [],
    }

    try:
        if not config_file.exists():
            logger.warning("Config file %s not found, using defaults", config_file)
            return defaults

        with open(config_file, "r") as f:
            config = json.load(f)

        logger.info("Loaded AI config from %s (updated: %s)", 
                    config_file, config.get("_updated", "unknown"))
        
        # Convert types (JSON may have strings where we expect int/bool)
        if "enabled" in config:
            val = config["enabled"]
            if isinstance(val, str):
                config["enabled"] = val in ("1", "true", "yes", "on")
            elif isinstance(val, int):
                config["enabled"] = val == 1
        
        if "idle_timeout" in config:
            try:
                config["idle_timeout"] = int(config["idle_timeout"])
            except (ValueError, TypeError):
                config["idle_timeout"] = 5

        # Remove metadata keys
        for key in ("_updated", "_source", "NAMESPACE", "CLASSVER", "models_cache"):
            config.pop(key, None)

        tools_enabled = config.get("tools_enabled", True)
        if isinstance(tools_enabled, str):
            tools_enabled = tools_enabled.strip().lower() in ("1", "true", "yes", "on")
        elif isinstance(tools_enabled, int):
            tools_enabled = tools_enabled == 1
        elif not isinstance(tools_enabled, bool):
            tools_enabled = bool(tools_enabled)
        config["tools_enabled"] = tools_enabled

        for flag_name, default in (
            ("read_only_tools_enabled", True),
            ("diagnostics_tools_enabled", True),
            ("actions_tools_enabled", True),
        ):
            flag_value = config.get(flag_name, default)
            if isinstance(flag_value, str):
                flag_value = flag_value.strip().lower() in ("1", "true", "yes", "on")
            elif isinstance(flag_value, int):
                flag_value = flag_value == 1
            elif not isinstance(flag_value, bool):
                flag_value = bool(flag_value)
            config[flag_name] = flag_value

        allow_generic = config.get("allow_generic_privileged_command", False)
        if isinstance(allow_generic, str):
            allow_generic = allow_generic.strip().lower() in ("1", "true", "yes", "on")
        elif isinstance(allow_generic, int):
            allow_generic = allow_generic == 1
        elif not isinstance(allow_generic, bool):
            allow_generic = bool(allow_generic)
        config["allow_generic_privileged_command"] = allow_generic

        wrapper_list = config.get("priv_tools_available", [])
        if isinstance(wrapper_list, str):
            wrapper_list = [item.strip() for item in wrapper_list.split("&") if item.strip()]
        elif isinstance(wrapper_list, list):
            wrapper_list = [str(item).strip() for item in wrapper_list if str(item).strip()]
        else:
            wrapper_list = []
        config["priv_tools_available"] = wrapper_list

        system_prompt = config.get("system_prompt", "")
        if not isinstance(system_prompt, str):
            system_prompt = str(system_prompt)
        config["system_prompt"] = system_prompt

        # Merge with defaults (defaults take precedence for missing keys)
        merged = {**defaults, **config}
        return merged

    except json.JSONDecodeError as e:
        logger.error("Failed to parse %s: %s", config_file, e)
        return defaults
    except PermissionError as e:
        logger.error("Permission denied reading %s: %s", config_file, e)
        return defaults
    except Exception as e:
        logger.error("Failed to load config from %s: %s", config_file, e)
        return defaults


# ---------------------------------------------------------------------------
# Global state (process-global, uvicorn is single-process)
# ---------------------------------------------------------------------------
_tool_executor: ToolExecutor | None = None
_session_manager: SessionManager | None = None
_provider_config: dict[str, Any] = {}
_auth_header_name = "X-BlueOnyx-AI-Auth"

# Audit logger singleton
_audit_logger: AuditLogger = AuditLogger()

# Idle tracking
_last_activity: float = 0.0          # wall clock of last activity
_idle_check_task: asyncio.Task | None = None


def get_idle_timeout() -> int:
    """Return idle timeout in seconds from CCE config."""
    return int(_provider_config.get("idle_timeout", 5)) * 60


def _generate_service_api_key() -> str:
    raise RuntimeError("service_api_key must be provided by CCE")


def _write_config_file(config_file: Path, config: dict[str, Any]) -> None:
    serializable = dict(config)
    serializable["_updated"] = int(time.time())
    serializable["_source"] = "AIService"
    with open(config_file, "w") as f:
        json.dump(serializable, f, indent=2, sort_keys=True, ensure_ascii=False)

async def _idle_watcher():
    """
    Background task: check idle timeout and exit if exceeded.

    Runs every 30 seconds. Resets timer on activity.
    Sends SIGTERM to self when timeout exceeded, triggering clean shutdown.
    """
    while True:
        await asyncio.sleep(30)

        idle_for = time.time() - _last_activity
        timeout = get_idle_timeout()

        if idle_for >= timeout:
            logger.info(
                "Idle timeout reached (%.0fs > %ds). Shutting down.",
                idle_for, timeout,
            )
            await stop_llama_if_idle()
            # Send SIGTERM to self -- triggers main() signal handler for clean shutdown
            import os
            import signal
            os.kill(os.getpid(), signal.SIGTERM)


def record_activity():
    """Reset idle timer on each request/activity."""
    global _last_activity
    _last_activity = time.time()
    logger.debug("Activity recorded, idle timer reset")


def get_provider() -> ExternalProvider:
    """Build an ExternalProvider from current CCE config."""
    return ExternalProvider(_provider_config)


# Module-level cache for the current model capability record.
# Updated by get_model_capability() on first call, and refreshed after a probe.
_current_model_capability: dict[str, Any] | None = None

def get_model_capability() -> dict[str, Any]:
    """Return the active model capability record for the current provider/model."""
    global _current_model_capability
    provider = str(_provider_config.get("provider", "local") or "local").strip()
    model = str(_provider_config.get("default_model", "") or "").strip()
    record = get_model_capabilities(provider, model)
    result = record.to_dict()
    _current_model_capability = result
    return result


def _update_model_capability_from_probe(probe_result: dict[str, Any]) -> None:
    """Update the cached model capability dict after a successful probe.
    
    This ensures build_agent() picks up the updated profile.
    """
    global _current_model_capability
    if probe_result and isinstance(probe_result, dict):
        _current_model_capability = probe_result
        logger.info(
            "Updated model capability cache from probe: profile=%s confidence=%.2f",
            probe_result.get("profile", "?"),
            float(probe_result.get("confidence", 0) or 0),
        )


async def _run_service_action(service: str, action: str, timeout: int = 30) -> bool:
    """Run a privileged systemd action via the approved wrapper."""
    try:
        proc = await asyncio.create_subprocess_exec(
            "sudo",
            "/home/ai/wrappers/ai-service-action",
            service,
            action,
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.PIPE,
        )
        stdout, stderr = await asyncio.wait_for(proc.communicate(), timeout=timeout)
        if proc.returncode != 0:
            logger.warning(
                "Service action %s %s failed: %s",
                service,
                action,
                stderr.decode().strip() or stdout.decode().strip() or f"exit {proc.returncode}",
            )
            return False
        logger.info("Service action %s %s succeeded", service, action)
        return True
    except Exception as exc:
        logger.warning("Service action %s %s raised: %s", service, action, exc)
        return False


def _require_service_auth(request: Request) -> None:
    expected = str(_provider_config.get("service_api_key", "") or "").strip()
    if not expected:
        logger.error("AI service auth key missing from configuration")
        raise HTTPException(
            status_code=503,
            detail="AI service is not configured for authenticated access",
        )

    supplied = request.headers.get(_auth_header_name, "")
    if not supplied or not hmac.compare_digest(supplied.strip(), expected):
        logger.warning("Rejected unauthorized AI request for %s", request.url.path)
        raise HTTPException(status_code=403, detail="Forbidden")


def _is_affirmative_confirmation(message: str) -> bool:
    """Return True when the user message clearly approves a pending action."""
    normalized = " ".join((message or "").strip().lower().split())
    if not normalized:
        return False

    phrases = {
        "yes",
        "y",
        "confirm",
        "confirmed",
        "yes, please",
        "yes please",
        "please do it",
        "go ahead",
        "proceed",
        "restart it",
        "do it",
        "ok",
        "okay",
    }
    if normalized in phrases:
        return True

    patterns = (
        r"^yes[, ]+please$",
        r"^yes[, ]+restart .*",
        r"^confirm .*",
        r"^please restart .*",
        r"^restart .*",
    )
    return any(re.search(pattern, normalized) for pattern in patterns)


def _extract_pending_confirmation(session: dict[str, Any]) -> dict[str, Any]:
    """Return pending confirmation state from the session or conversation history.

    The primary source is the explicit pending_confirmation field. If that is
    missing, fall back to the most recent pending_confirmation tool result in
    the stored messages so a confirmation does not get lost if the session
    payload was partially rewritten.
    """
    pending = session.get("pending_confirmation")
    if isinstance(pending, dict) and pending:
        return pending

    messages = session.get("messages") or []
    if not isinstance(messages, list) or not messages:
        return {}

    for idx in range(len(messages) - 1, -1, -1):
        msg = messages[idx]
        if not isinstance(msg, dict):
            continue

        if msg.get("role") == "assistant":
            # A later assistant response means the previous write tool was
            # already resolved, so do not resurrect older pending state.
            return {}

        if msg.get("role") != "tool":
            continue

        content = msg.get("content")
        if not isinstance(content, str):
            continue

        try:
            parsed = json.loads(content)
        except (TypeError, json.JSONDecodeError):
            continue

        if not isinstance(parsed, dict):
            continue
        if parsed.get("status") != "pending_confirmation":
            continue

        tool_call_id = str(msg.get("tool_call_id", "") or "").strip()
        tool_name = ""
        tool_args: dict[str, Any] = {}

        for prev_idx in range(idx - 1, -1, -1):
            prev = messages[prev_idx]
            if not isinstance(prev, dict) or prev.get("role") != "assistant":
                continue
            tool_calls = prev.get("tool_calls")
            if not isinstance(tool_calls, list):
                continue
            for tc in tool_calls:
                if not isinstance(tc, dict):
                    continue
                if tool_call_id and str(tc.get("id", "") or "").strip() != tool_call_id:
                    continue
                function = tc.get("function")
                if isinstance(function, dict):
                    tool_name = str(function.get("name", "") or "").strip()
                    args_raw = function.get("arguments", "{}")
                    if isinstance(args_raw, str):
                        try:
                            tool_args = json.loads(args_raw) if args_raw else {}
                        except json.JSONDecodeError:
                            tool_args = {}
                break
            if tool_name:
                break

        return {
            "tool_call_id": tool_call_id,
            "tool": tool_name,
            "args": tool_args,
            "reason": "Recovered from pending tool result",
        }

    return {}


def _extract_explicit_service_action_request(message: str) -> str:
    """Return a service name if the user is making a fresh service-action request."""
    normalized = " ".join((message or "").strip().lower().split())
    if not normalized:
        return ""

    action_re = re.compile(
        r"\b(?:please\s+)?(?:can\s+you\s+|could\s+you\s+|would\s+you\s+)?"
        r"(start|stop|restart|reload)\s+"
        r"(?:the\s+)?(?:service\s+)?(?P<service>[a-z0-9][a-z0-9._-]*)\b"
    )
    match = action_re.search(normalized)
    if not match:
        return ""

    service = match.group("service").strip(".,:;!?")
    known = {
        "httpd", "nginx", "dovecot", "postfix", "proftpd", "named", "mariadb",
        "mysql", "clamd", "spamassassin", "cron", "rsyslog", "sshd",
        "sausalito-cce", "sausalito-cced", "sausalito-ai", "sausalito-llama",
    }
    if service in known or service.startswith("base-"):
        return service
    return ""


async def _execute_pending_confirmation(
    session_id: str,
    run_as: str,
    model_name: str,
    pending: dict[str, Any],
) -> str:
    """Execute a previously confirmed write tool from session state."""
    if _tool_executor is None or _session_manager is None:
        raise RuntimeError("Service not initialized")

    tool_name = str(pending.get("tool", "") or "").strip()
    tool_args = pending.get("args", {}) or {}
    tool_call_id = str(pending.get("tool_call_id", "") or "").strip()
    username = str(pending.get("username", "") or "").strip() or run_as
    user_session_id = str(pending.get("user_session_id", "") or "").strip()

    tool_def = _tool_executor.get_tool(tool_name)
    if not tool_def:
        raise RuntimeError(f"Unknown pending tool: {tool_name}")

    if not isinstance(tool_args, dict):
        raise RuntimeError("Pending tool arguments are invalid")

    if tool_def.is_write_operation:
        _audit_logger.log_confirmation_granted(
            session_id=session_id,
            user=run_as,
            tool_name=tool_name,
            model=model_name,
        )

    if tool_name == "run_privileged_command":
        result = await _tool_executor.execute(
            tool_name,
            tool_args,
            run_as,
            context={"model_profile": "guided"},
        )
    else:
        result = await _tool_executor.execute(tool_name, tool_args, run_as)

    await _session_manager.clear_pending_confirmation(session_id)

    if not result.success:
        _audit_logger.log_tool_execution(
            session_id=session_id,
            user=run_as,
            tool_name=tool_name,
            tool_args=tool_args,
            success=False,
            duration_ms=0.0,
            model=model_name,
        )
        return result.error or f"Failed to execute {tool_name}"

    _audit_logger.log_tool_execution(
        session_id=session_id,
        user=run_as,
        tool_name=tool_name,
        tool_args=tool_args,
        success=True,
        duration_ms=0.0,
        model=model_name,
    )

    output = (result.output or "").strip()
    if not output:
        output = f"{tool_name} completed successfully."
    if tool_call_id:
        logger.info("Executed pending confirmation tool %s for session %s", tool_name, session_id)
    return output


async def ensure_llama_running() -> bool:
    """Validate, start, and wait for the local llama backend on demand."""
    if str(_provider_config.get("provider", "local") or "local").strip().lower() != "local":
        return True

    capability = await _get_local_inference_capability()
    if not capability.get("available", False):
        reason = str(capability.get("reason", "local inference is unavailable"))
        logger.error("Local inference preflight failed: %s", reason)
        raise RuntimeError(reason)

    if not await _run_service_action("sausalito-llama", "start"):
        raise RuntimeError("The local inference service could not be started")

    deadline = asyncio.get_running_loop().time() + 60.0
    while asyncio.get_running_loop().time() < deadline:
        if await _llama_server_ready():
            logger.info(
                "Local inference ready using CPU backend %s",
                capability.get("cpu_backend", "runtime-selected"),
            )
            return True
        await asyncio.sleep(0.5)

    await _run_service_action("sausalito-llama", "stop")
    raise RuntimeError("The local inference service started but did not become ready within 60 seconds")


async def _get_local_inference_capability() -> dict[str, Any]:
    """Run the packaged hardware/model preflight without blocking the event loop."""
    command = ["/home/ai/bin/blueonyx-llama-check"]
    model = str(_provider_config.get("default_model", "") or "").strip()
    if model:
        command.extend(["--model", model])

    try:
        proc = await asyncio.create_subprocess_exec(
            *command,
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.PIPE,
        )
        stdout, stderr = await asyncio.wait_for(proc.communicate(), timeout=20)
        payload = json.loads(stdout.decode("utf-8", errors="replace"))
        if not isinstance(payload, dict):
            raise ValueError("capability helper returned a non-object response")
        if proc.returncode != 0:
            payload["available"] = False
        return payload
    except FileNotFoundError:
        return {"available": False, "reason": "The local inference capability helper is not installed"}
    except Exception as exc:
        detail = stderr.decode("utf-8", errors="replace").strip() if "stderr" in locals() else ""
        return {"available": False, "reason": detail or f"Local inference preflight failed: {exc}"}


async def _llama_server_ready() -> bool:
    """Return True once llama-server answers its localhost health endpoint."""
    writer = None
    try:
        reader, writer = await asyncio.wait_for(
            asyncio.open_connection("127.0.0.1", 8081), timeout=2.0
        )
        writer.write(b"GET /health HTTP/1.0\r\nHost: 127.0.0.1\r\n\r\n")
        await writer.drain()
        status_line = await asyncio.wait_for(reader.readline(), timeout=2.0)
        return status_line.startswith(b"HTTP/") and b" 200 " in status_line
    except (OSError, asyncio.TimeoutError):
        return False
    finally:
        if writer is not None:
            writer.close()
            if hasattr(writer, "wait_closed"):
                try:
                    await writer.wait_closed()
                except Exception:
                    pass


async def _llama_service_running() -> bool:
    """Read the local backend's systemd state without changing it."""
    try:
        proc = await asyncio.create_subprocess_exec(
            "systemctl", "is-active", "--quiet", "sausalito-llama.service"
        )
        return await proc.wait() == 0
    except Exception:
        return False


async def stop_llama_if_idle() -> bool:
    """Stop the llama service when the AI process goes idle."""
    if str(_provider_config.get("provider", "local") or "local").strip().lower() != "local":
        return True
    return await _run_service_action("sausalito-llama", "stop")


async def stop_llama_if_running() -> bool:
    """Stop the llama service if it is active, regardless of the current provider."""
    proc = await asyncio.create_subprocess_exec(
        "systemctl",
        "is-active",
        "--quiet",
        "sausalito-llama.service",
    )
    rc = await proc.wait()
    if rc != 0:
        return True
    return await _run_service_action("sausalito-llama", "stop")


async def _maybe_run_capability_probe(provider: ExternalProvider) -> dict[str, Any] | None:
    """Run a capability probe if the current model has low heuristic confidence.

    Returns probe result dict if a probe was run, None otherwise.
    The probe updates the persistent capability store on completion.
    """
    try:
        from sausalito_ai.capability_probe import run_capability_probe
    except ImportError:
        logger.warning("capability_probe module not available, skipping probe")
        return None

    model_caps = get_model_capability()
    confidence = float(model_caps.get("confidence", 0.0) or 0.0)
    source = str(model_caps.get("source", "heuristic"))

    # Only probe if heuristic confidence is low and no probe has been run yet
    if source == "probe" or confidence >= PROBE_CONFIDENCE_THRESHOLD:
        logger.info("Skipping capability probe: confidence=%.2f source=%s", confidence, source)
        return None

    logger.info("Running capability probe: confidence=%.2f source=%s", confidence, source)
    try:
        result = await run_capability_probe(provider, timeout=20.0)
        probe_dict = result.to_dict()
        logger.info(
            "Capability probe completed: overall=%.2f profile=%s elapsed=%.1fs",
            result.overall_score, result.suggested_profile, result.elapsed_seconds,
        )

        # Update the persistent capability store
        store = ModelCapabilityStore()
        provider_name = str(_provider_config.get("provider", "local") or "local").strip()
        model_name = str(_provider_config.get("default_model", "") or "").strip()
        updated = store.update_from_probe(provider_name, model_name, probe_dict)
        logger.info("Updated model profile: %s/%s → %s (confidence=%.2f)",
                     provider_name, model_name, updated.profile, updated.confidence)

        return updated.to_dict()
    except Exception as exc:
        logger.warning("Capability probe failed: %s", exc)
        return None


def build_agent() -> Agent:
    """Build a new Agent instance (fresh per request context)."""
    if _tool_executor is None or _session_manager is None:
        raise RuntimeError("Service not initialized")
    model_caps = get_model_capability()
    logger.info(
        "Model capability profile: provider=%s model=%s profile=%s confidence=%.2f",
        model_caps.get("provider", "unknown"),
        model_caps.get("model", ""),
        model_caps.get("profile", "guided"),
        float(model_caps.get("confidence", 0.0) or 0.0),
    )
    return Agent(
        provider=get_provider(),
        session_manager=_session_manager,
        tool_executor=_tool_executor,
        system_prompt=str(_provider_config.get("system_prompt", "") or ""),
        model_profile=str(model_caps.get("profile", "guided") or "guided"),
        tools_enabled=bool(_provider_config.get("tools_enabled", True)),
        allowed_tool_categories=[
            category
            for category, enabled in (
                ("read_only", bool(_provider_config.get("read_only_tools_enabled", True))),
                ("diagnostics", bool(_provider_config.get("diagnostics_tools_enabled", True))),
                ("actions", bool(_provider_config.get("actions_tools_enabled", True))),
                ("advanced", bool(_provider_config.get("allow_generic_privileged_command", False)) and bool(_provider_config.get("priv_tools_available", []))),
            )
            if enabled
        ],
        allow_generic_privileged_command=bool(
            _provider_config.get("allow_generic_privileged_command", False)
        ) and bool(_provider_config.get("priv_tools_available", [])),
    )


# ---------------------------------------------------------------------------
# SSE helpers
# ---------------------------------------------------------------------------

async def sse_event(data: dict[str, Any], event: str = "message") -> bytes:
    payload = json.dumps(data, ensure_ascii=False)
    return f"event: {event}\ndata: {payload}\n\n".encode()


async def stream_agent_events(
    message: str,
    session_id: str,
    run_as: str,
    username: str = "",
    user_session_id: str = "",
) -> AsyncGenerator[bytes, None]:
    """Run the agent and yield SSE-formatted events.

    Resets idle timer on every event yielded (chat is active).
    """
    # Audit: log user query
    model_name = str(_provider_config.get("default_model", "") or "").strip()
    _audit_logger.log_user_query(session_id, run_as, message, model=model_name)

    # Track tool-call timing for audit logging
    _tool_start_times: dict[str, float] = {}
    _final_response_parts: list[str] = []
    _iteration_count = 0

    try:
        await ensure_llama_running()
        # Run capability probe if model has low heuristic confidence
        probe_result = await _maybe_run_capability_probe(get_provider())
        if probe_result is not None:
            # Re-read the (now updated) capability record
            _update_model_capability_from_probe(probe_result)
        agent = build_agent()

        from sausalito_ai.tools.system_tools import (
            create_run_privileged_command_handler,
            create_system_disk_space_handler,
            create_system_memory_handler,
            create_system_network_bandwidth_handler,
            create_system_network_counters_handler,
            create_system_network_dns_handler,
            create_system_network_interfaces_handler,
            create_system_network_routes_handler,
            create_system_network_sockets_handler,
            create_system_network_status_handler,
            create_system_uname_handler,
            RunPrivilegedCommandTool,
            SystemDiskSpaceTool,
            SystemMemoryTool,
            SystemNetworkBandwidthTool,
            SystemNetworkCountersTool,
            SystemNetworkDnsTool,
            SystemNetworkInterfacesTool,
            SystemNetworkRoutesTool,
            SystemNetworkSocketsTool,
            SystemNetworkStatusTool,
            SystemUnameTool,
        )

        # Register the exact uname tool unconditionally.
        # It is read-only and runs locally, so it does not need user/session context.
        agent.tool_executor.register_tool(
            SystemUnameTool(),
            create_system_uname_handler(username, user_session_id),
        )

        agent.tool_executor.register_tool(
            SystemDiskSpaceTool(),
            create_system_disk_space_handler(username, user_session_id),
        )

        agent.tool_executor.register_tool(
            SystemMemoryTool(),
            create_system_memory_handler(username, user_session_id),
        )

        agent.tool_executor.register_tool(
            SystemNetworkStatusTool(),
            create_system_network_status_handler(username, user_session_id),
        )

        agent.tool_executor.register_tool(
            SystemNetworkInterfacesTool(),
            create_system_network_interfaces_handler(username, user_session_id),
        )

        agent.tool_executor.register_tool(
            SystemNetworkCountersTool(),
            create_system_network_counters_handler(username, user_session_id),
        )

        agent.tool_executor.register_tool(
            SystemNetworkRoutesTool(),
            create_system_network_routes_handler(username, user_session_id),
        )

        agent.tool_executor.register_tool(
            SystemNetworkSocketsTool(),
            create_system_network_sockets_handler(username, user_session_id),
        )

        agent.tool_executor.register_tool(
            SystemNetworkBandwidthTool(),
            create_system_network_bandwidth_handler(username, user_session_id),
        )

        agent.tool_executor.register_tool(
            SystemNetworkDnsTool(),
            create_system_network_dns_handler(username, user_session_id),
        )

        # Replace run_privileged_command handler only when we have user context.
        if username and user_session_id:
            if bool(_provider_config.get("allow_generic_privileged_command", False)) and bool(_provider_config.get("priv_tools_available", [])):
                handler = create_run_privileged_command_handler(
                    username,
                    user_session_id,
                    list(_provider_config.get("priv_tools_available", [])),
                )
                agent.tool_executor.register_tool(RunPrivilegedCommandTool(), handler)
                logger.info(
                    "Set privileged command handler for user %s with %d wrapper(s)",
                    username,
                    len(_provider_config.get("priv_tools_available", [])),
                )
            else:
                logger.info("Generic privileged command tool disabled or not configured")

        session = await _session_manager.get(session_id)
        pending_confirmation = _extract_pending_confirmation(session)
        pending_service = str(
            (pending_confirmation.get("args") or {}).get("service", "") or ""
        ).strip().lower()
        explicit_service = _extract_explicit_service_action_request(message)
        if pending_confirmation and _is_affirmative_confirmation(message):
            tool_name = str(pending_confirmation.get("tool", "") or "").strip()
            logger.info("Confirming pending tool %s for session %s", tool_name, session_id)
            try:
                output = await _execute_pending_confirmation(
                    session_id=session_id,
                    run_as=run_as,
                    model_name=model_name,
                    pending=pending_confirmation,
                )
                await _session_manager.add_message(session_id, "assistant", output)
                yield await sse_event({"message": output}, "message")
                yield await sse_event({"done": True}, "done")
                return
            except Exception as exc:
                logger.exception("Failed to execute pending confirmation for session %s", session_id)
                await _session_manager.clear_pending_confirmation(session_id)
                err_msg = str(exc) or "Failed to execute confirmed action"
                _audit_logger.log_error(
                    session_id=session_id,
                    user=run_as,
                    error_type="confirmation_execution_failed",
                    message=err_msg,
                    model=model_name,
                )
                yield await sse_event({"error": err_msg}, "error")
                yield await sse_event({"done": True}, "done")
                return

        if pending_confirmation and explicit_service and explicit_service != pending_service:
            logger.info(
                "Clearing stale pending confirmation for %s due to new service request for %s",
                pending_service or "?",
                explicit_service,
            )
            await _session_manager.clear_pending_confirmation(session_id)
            session = await _session_manager.get(session_id)
            pending_confirmation = {}
        
        async for event in agent.run(message=message, session_id=session_id, run_as=run_as):
            record_activity()  # each output event = active

            etype = event.get("type", "message")

            if etype == "delta":
                _final_response_parts.append(event.get("content", ""))
                yield await sse_event({"message": event["content"]}, "message")

            elif etype == "tool_call":
                _tool_start_times[event["id"]] = time.time()
                requires_confirmation = event.get("requires_confirmation", False)
                yield await sse_event({
                    "type": "tool_call",
                    "id": event["id"],
                    "tool": event["name"],
                    "args": event.get("arguments", {}),
                    "requires_confirmation": requires_confirmation,
                }, "tool_call")

            elif etype == "tool_result":
                tc_id = event["id"]
                result = event.get("result", {})
                # Audit: log tool execution (with timing if available)
                start_ts = _tool_start_times.pop(tc_id, None)
                duration_ms = (
                    round((time.time() - start_ts) * 1000, 2)
                    if start_ts else 0.0
                )
                _audit_logger.log_tool_execution(
                    session_id=session_id,
                    user=run_as,
                    tool_name=result.get("tool", tc_id),
                    tool_args=result.get("args", {}),
                    success=result.get("status") == "ok",
                    duration_ms=duration_ms,
                    model=model_name,
                )
                yield await sse_event({
                    "type": "tool_result",
                    "id": tc_id,
                    "result": result,
                }, "tool_result")

            elif etype == "confirmation_required":
                tc_id = event.get("tool_call_id", "")
                tool_name = event.get("tool", "")
                tool_args = event.get("args", {})
                _tool_start_times.pop(tc_id, None)  # clean up if any
                _audit_logger.log_confirmation_request(
                    session_id=session_id,
                    user=run_as,
                    tool_name=tool_name,
                    tool_args=tool_args,
                    model=model_name,
                )
                yield await sse_event({
                    "type": "confirmation_required",
                    "tool_call_id": tc_id,
                    "tool": tool_name,
                    "reason": event.get("reason", ""),
                }, "confirmation_required")

            elif etype == "error":
                err_msg = event.get("message", "Unknown error")
                _audit_logger.log_error(
                    session_id=session_id,
                    user=run_as,
                    error_type="agent_error",
                    message=err_msg,
                    model=model_name,
                )
                yield await sse_event({"error": err_msg}, "error")

            elif etype == "done":
                # Audit: log model response summary
                _audit_logger.log_model_response(
                    session_id=session_id,
                    user=run_as,
                    model=model_name,
                    response_len=len("".join(_final_response_parts)),
                    iterations=_iteration_count,
                )
                yield await sse_event({"done": True}, "done")
                break

    except Exception as e:
        logger.exception("stream_agent_events error")
        _audit_logger.log_error(
            session_id=session_id,
            user=run_as,
            error_type="stream_exception",
            message=str(e),
            model=model_name,
        )
        yield await sse_event({"error": str(e)}, "error")
        yield await sse_event({"done": True}, "done")


# ---------------------------------------------------------------------------
# FastAPI app with lifespan (modern replacement for deprecated on_event)
# ---------------------------------------------------------------------------
from contextlib import asynccontextmanager

@asynccontextmanager
async def lifespan(app: FastAPI):
    global _tool_executor, _session_manager, _provider_config
    global _idle_check_task, _last_activity

    # Startup code
    logger.info("BlueOnyx AI Service starting (pid=%d)...", os.getpid())

    _provider_config = load_cce_config()
    logger.info(
        "AI config: enabled=%s provider=%s model=%s idle_timeout=%dmin",
        _provider_config["enabled"],
        _provider_config["provider"],
        _provider_config.get("default_model", ""),
        _provider_config["idle_timeout"],
    )

    # Keep the llama backend off unless a local request explicitly needs it.
    # This prevents stale llama-server instances from lingering between sessions.
    await stop_llama_if_running()

    _session_manager = SessionManager(session_dir=SESSION_DIR)
    _tool_executor = ToolExecutor()

    record_activity()
    _idle_check_task = asyncio.create_task(_idle_watcher())

    logger.info("AI Service ready.")
    
    yield
    
    # Shutdown code
    logger.info("AI Service shutting down...")
    if _idle_check_task:
        _idle_check_task.cancel()
        try:
            await _idle_check_task
        except asyncio.CancelledError:
            pass
    logger.info("AI Service stopped.")

app = FastAPI(title="BlueOnyx AI Service", version="1.0.0", lifespan=lifespan)


@app.get("/health")
async def health():
    """Systemd readiness probe."""
    record_activity()
    result = {
        "status": "ok",
        "pid": os.getpid(),
        "provider": _provider_config.get("provider", "unknown"),
        "model": _provider_config.get("default_model", ""),
        "idle_timeout_min": _provider_config.get("idle_timeout", 5),
        "uptime_since": time.time() - _last_activity,
    }
    if str(_provider_config.get("provider", "local") or "local").strip().lower() == "local":
        local_inference = await _get_local_inference_capability()
        local_inference["running"] = await _llama_service_running()
        result["local_inference"] = local_inference
    return result


@app.post("/chat")
async def chat(request: Request):
    """
    Streaming chat endpoint.

    Body (JSON):
        message           -- user message (str, required)
        session_id        -- chat session id (str, required)
        run_as            -- user to run tools as (str, default "blueonyx_ai")
        ai_username       -- BlueOnyx username for privileged commands (str, optional)
        ai_user_session_id-- BlueOnyx session ID for privileged commands (str, optional)

    Returns: text/event-stream
    Resets idle timer on every streamed event.
    """
    body = await request.json()
    message = body.get("message", "").strip()
    session_id = body.get("session_id", "")
    run_as = body.get("run_as", "blueonyx_ai")
    username = body.get("ai_username", "")
    user_session_id = body.get("ai_user_session_id", "")

    if not message:
        raise HTTPException(status_code=400, detail="message is required")
    if not session_id:
        raise HTTPException(status_code=400, detail="session_id is required")

    _require_service_auth(request)

    # Sanitize session_id to safe filename characters
    safe_sid = "".join(c for c in session_id if c.isalnum() or c in "-_.@")
    if not safe_sid:
        safe_sid = "default"

    record_activity()
    logger.info("chat session=%s run_as=%s msg_len=%d user=%s", 
                safe_sid, run_as, len(message), username or "none")

    return StreamingResponse(
        stream_agent_events(
            message=message, 
            session_id=safe_sid, 
            run_as=run_as,
            username=username,
            user_session_id=user_session_id
        ),
        media_type="text/event-stream",
        headers={
            "Cache-Control": "no-cache",
            "Connection": "keep-alive",
            "X-Accel-Buffering": "no",
        },
    )


@app.post("/function")
async def function_call(request: Request):
    """
    Direct tool execution endpoint (for cross-module use).

    Body (JSON):
        function -- tool name (str, required)
        args     -- tool arguments (dict, default {})
        run_as   -- user to run as (str, default "blueonyx_ai")
        confirmed -- set true for write tools (bool, default false)

    Returns: JSON {status, output, error}
    Resets idle timer on each call (another call may follow).
    """
    body = await request.json()
    tool_name = body.get("function", "").strip()
    tool_args = body.get("args", {})
    run_as = body.get("run_as", "blueonyx_ai")

    if not tool_name:
        raise HTTPException(status_code=400, detail="function is required")

    if _tool_executor is None:
        raise HTTPException(status_code=503, detail="Service not initialized")

    _require_service_auth(request)

    record_activity()
    logger.info("function tool=%s run_as=%s", tool_name, run_as)

    tool_def = _tool_executor.get_tool(tool_name)
    if not tool_def:
        raise HTTPException(status_code=404, detail=f"Unknown tool: {tool_name}")

    if tool_def.is_write_operation and not body.get("confirmed", False):
        raise HTTPException(
            status_code=403,
            detail=f"Tool '{tool_name}' is a write operation and requires confirmation",
        )

    try:
        start_ts = time.time()
        result = await _tool_executor.execute(tool_name, tool_args, run_as)
        duration_ms = round((time.time() - start_ts) * 1000, 2)
        model_name = str(_provider_config.get("default_model", "") or "").strip()
        _audit_logger.log_tool_execution(
            session_id="function-call",
            user=run_as,
            tool_name=tool_name,
            tool_args=tool_args,
            success=result.success,
            duration_ms=duration_ms,
            model=model_name,
        )
        response: dict[str, Any] = {
            "status": "ok" if result.success else "error",
            "output": result.output,
        }
        if result.error:
            response["error"] = result.error
        return response
    except Exception as e:
        logger.exception("function execution failed")
        model_name = str(_provider_config.get("default_model", "") or "").strip()
        _audit_logger.log_error(
            session_id="function-call",
            user=run_as,
            error_type="function_exception",
            message=str(e),
            model=model_name,
        )
        raise HTTPException(status_code=500, detail=str(e))


@app.get("/")
async def root():
    return HTMLResponse(
        "<html><body><h1>BlueOnyx AI Service</h1>"
        "<p>POST /chat -- streaming LLM chat (SSE)</p>"
        "<p>POST /function -- direct tool execution (JSON)</p>"
        "<p>GET /health -- readiness probe</p>"
        "</body></html>",
        status_code=200,
    )


# ---------------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------------

def main():
    def handle_sig(sig, frame):
        logger.info("Received signal %d", sig)
        sys.exit(0)

    signal.signal(signal.SIGTERM, handle_sig)
    signal.signal(signal.SIGINT, handle_sig)

    # Check for systemd socket activation
    # With StandardInput=socket, systemd passes the listening socket on fd 0 (stdin)
    use_socket_activation = False
    try:
        import socket
        # Try to create a socket object from fd 0 without closing it
        test_sock = socket.socket(fileno=0)
        # If this works and getsockname() succeeds, fd 0 is a listening socket
        sock_name = test_sock.getsockname()
        use_socket_activation = True
        logger.info("Detected socket activation on fd 0: %s", sock_name)
        # IMPORTANT: test_sock is just a Python object wrapping fd 0
        # We must NOT close it, otherwise uvicorn loses the socket
    except (OSError, AttributeError, ValueError, ImportError):
        # fd 0 is not a socket, fall through to normal bind
        pass
    
    if use_socket_activation:
        logger.info("Starting with socket activation (fd=0)")
        uvicorn.run(
            app,
            fd=0,
            log_level="info",
        )
    else:
        # Normal startup: bind to port
        logger.info("No socket activation detected, binding to 127.0.0.1:1972")
        uvicorn.run(
            app,
            host="127.0.0.1",
            port=1972,
            log_level="info",
        )


if __name__ == "__main__":
    main()
