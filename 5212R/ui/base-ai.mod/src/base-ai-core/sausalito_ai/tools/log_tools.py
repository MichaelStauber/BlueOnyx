# Log analysis tools for BlueOnyx AI Agent
# Copyright (c) 2008-2026 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2026 Team BlueOnyx, BLUEONYX.IT
# All Rights Reserved.
from __future__ import annotations

import asyncio
import glob
import logging
import os
import subprocess
from pathlib import Path
import re

from .base import ToolDefinition, ToolResult

logger = logging.getLogger("sausalito_ai.tools.log")

DEFAULT_ADMIN_LOG_GLOBS = [
    "/var/log/messages*",
    "/var/log/secure*",
    "/var/log/maillog*",
    "/var/log/cron*",
    "/var/log/httpd/*",
    "/var/log/sshd*",
    "/var/log/audit/audit.log*",
]

DEFAULT_MAIL_LOG_GLOBS = [
    "/var/log/maillog*",
]


def register_tools(executor):
    """Register log analysis tools with the executor."""

    executor.register_tool(
        ToolDefinition(
            name="read_file",
            description="Read a file from /var/log/. Returns the file contents or an error if the file is too large. Use search_logs first for large files to narrow down the section you need.",
            properties={
                "path": {
                    "type": "string",
                    "description": "Absolute path to the file, must be under /var/log/",
                },
                "max_lines": {
                    "type": "integer",
                    "description": "Maximum number of lines to read (default 500, max 2000)",
                    "default": 500,
                },
            },
            required=["path"],
            category="read_only",
        ),
        handle_read_file,
    )

    executor.register_tool(
        ToolDefinition(
            name="search_logs",
            description="Search through log files using grep. Returns matching lines with surrounding context. Use this first to find relevant sections before reading large files.",
            properties={
                "pattern": {
                    "type": "string",
                    "description": "Regular expression pattern to search for",
                },
                "path": {
                    "type": "string",
                    "description": "File path or glob pattern under /var/log/ (default: /var/log/mail* for maillog)",
                    "default": "/var/log/mail*",
                },
                "context_lines": {
                    "type": "integer",
                    "description": "Number of context lines before and after each match",
                    "default": 3,
                },
                "max_matches": {
                    "type": "integer",
                    "description": "Maximum number of matches to return",
                    "default": 50,
                },
            },
            required=["pattern"],
            category="read_only",
        ),
        handle_search_logs,
    )

    executor.register_tool(
        ToolDefinition(
            name="search_admin_logs",
            description=(
                "Search across the common Linux admin logs under /var/log/ for authentication failures, "
                "mail delivery issues, service errors, cron activity, SSH failures, and related incidents. "
                "This is the preferred tool when the user asks to check general system logs."
            ),
            properties={
                "pattern": {
                    "type": "string",
                    "description": "Regular expression pattern to search for",
                },
                "paths": {
                    "type": "array",
                    "items": {"type": "string"},
                    "description": "Optional list of /var/log/ paths or globs. If omitted, common admin logs are searched.",
                },
                "context_lines": {
                    "type": "integer",
                    "description": "Number of context lines before and after each match",
                    "default": 2,
                },
                "max_matches": {
                    "type": "integer",
                    "description": "Maximum number of matches to return",
                    "default": 100,
                },
                "ignore_case": {
                    "type": "boolean",
                    "description": "Search case-insensitively",
                    "default": True,
                },
            },
            required=["pattern"],
            category="read_only",
        ),
        handle_search_admin_logs,
    )

    executor.register_tool(
        ToolDefinition(
            name="mail_stats",
            description=(
                "Summarize mail activity from mail logs, including submitted/received messages, outbound deliveries, "
                "local deliveries, rejected/deferred/bounced mail, spam hits, and top senders/recipients. "
                "Use when the user asks for mail statistics or counts."
            ),
            properties={
                "paths": {
                    "type": "array",
                    "items": {"type": "string"},
                    "description": "Optional list of /var/log/ paths or globs to scan for mail statistics.",
                },
                "user": {
                    "type": "string",
                    "description": "Optional username, sender, recipient, or email address to filter the statistics.",
                },
                "days": {
                    "type": "integer",
                    "description": "Only include mail logs modified within the last N days. Use 0 for all available logs.",
                    "default": 0,
                },
                "limit": {
                    "type": "integer",
                    "description": "Maximum number of top senders/recipients to show.",
                    "default": 5,
                },
            },
            required=[],
            category="read_only",
        ),
        handle_mail_stats,
    )

    executor.register_tool(
        ToolDefinition(
            name="mail_health",
            description=(
                "Provide a concise mail health summary from mail logs, focusing on delivery status, rejects, "
                "deferrals, bounces, spam events, and recent trends. Use this when the user asks whether mail "
                "flow or filtering looks healthy."
            ),
            properties={
                "paths": {
                    "type": "array",
                    "items": {"type": "string"},
                    "description": "Optional list of /var/log/ paths or globs to scan for mail health.",
                },
                "user": {
                    "type": "string",
                    "description": "Optional username, sender, recipient, or email address to filter the health summary.",
                },
                "days": {
                    "type": "integer",
                    "description": "Only include mail logs modified within the last N days. Use 0 for all available logs.",
                    "default": 7,
                },
                "limit": {
                    "type": "integer",
                    "description": "Maximum number of top senders/recipients to show.",
                    "default": 3,
                },
            },
            required=[],
            category="read_only",
        ),
        handle_mail_health,
    )

    executor.register_tool(
        ToolDefinition(
            name="spam_abuse",
            description=(
                "Identify spam-abuse sources from mail logs: which authenticated user is blasting spam, "
                "suspicious sender volumes, top connecting client IPs, and spam-classification hits. "
                "Use when the user asks who is sending spam, which account is compromised, or suspects mail abuse."
            ),
            properties={
                "paths": {
                    "type": "array",
                    "items": {"type": "string"},
                    "description": "Optional list of /var/log/ paths or globs to scan for spam abuse analysis.",
                },
                "user": {
                    "type": "string",
                    "description": "Optional username or email address to filter the abuse analysis.",
                },
                "days": {
                    "type": "integer",
                    "description": "Only include mail logs modified within the last N days. Use 0 for all available logs.",
                    "default": 7,
                },
                "limit": {
                    "type": "integer",
                    "description": "Maximum number of top offenders/IPs to show.",
                    "default": 10,
                },
            },
            required=[],
            category="read_only",
        ),
        handle_spam_abuse,
    )

    executor.register_tool(
        ToolDefinition(
            name="journalctl_query",
            description="Query the systemd journal. Use this to search for service logs by unit name, time range, or priority level.",
            properties={
                "unit": {
                    "type": "string",
                    "description": "Systemd unit name (e.g., 'httpd', 'dovecot', 'postfix')",
                },
                "since": {
                    "type": "string",
                    "description": "Start time (e.g., 'yesterday', '2025-05-01', '1 hour ago')",
                    "default": "today",
                },
                "until": {
                    "type": "string",
                    "description": "End time (e.g., 'now')",
                },
                "priority": {
                    "type": "string",
                    "description": "Priority level: emerg, alert, crit, err, warning, notice, info, debug",
                },
                "lines": {
                    "type": "integer",
                    "description": "Number of lines to return",
                    "default": 50,
                },
            },
            required=[],
            category="diagnostics",
        ),
        handle_journalctl_query,
    )


async def handle_read_file(args: dict, run_as: str) -> ToolResult:
    path = args.get("path", "")
    max_lines = min(args.get("max_lines", 500), 2000)

    # Validate path
    if not path.startswith("/var/log/"):
        return ToolResult(success=False, error=f"Path must be under /var/log/: {path}")

    # Resolve symlinks
    real_path = os.path.realpath(path)
    if not real_path.startswith("/var/log/"):
        return ToolResult(success=False, error=f"Symlink resolves outside /var/log/: {real_path}")

    if not os.path.isfile(real_path):
        return ToolResult(success=False, error=f"File not found: {real_path}")

    # Check file size (refuse files > 10 MB — use search_logs instead)
    size = os.path.getsize(real_path)
    if size > 10 * 1024 * 1024:
        return ToolResult(
            success=False,
            error=f"File too large ({size / 1024 / 1024:.1f} MB). Use search_logs to find the relevant section first.",
        )

    try:
        # Try reading directly first
        with open(real_path, "r", errors="replace") as f:
            lines = f.readlines()

        total = len(lines)
        if total > max_lines:
            lines = lines[-max_lines:]

        content = "".join(lines)
        return ToolResult(
            success=True,
            output=content,
            data={"path": real_path, "total_lines": total, "returned_lines": len(lines)},
        )
    except PermissionError:
        # Need root — use sudo wrapper
        cmd = ["/home/ai/wrappers/ai-read-log", real_path]
        result = await _run_as(cmd, "root", timeout=15)
        if not result.success:
            return result
        lines = result.output.splitlines(keepends=True)
        total = len(lines)
        if total > max_lines:
            lines = lines[-max_lines:]
        return ToolResult(
            success=True,
            output="".join(lines),
            data={"path": real_path, "total_lines": total, "returned_lines": len(lines)},
        )


async def handle_search_logs(args: dict, run_as: str) -> ToolResult:
    pattern = args.get("pattern", "")
    path = args.get("path", "/var/log/mail*")
    context = args.get("context_lines", 3)
    max_matches = args.get("max_matches", 50)

    # Validate path pattern
    if not path.startswith("/var/log/"):
        return ToolResult(success=False, error=f"Path must be under /var/log/: {path}")

    cmd = ["grep", "-n", f"-C{context}", pattern] + path.split()
    result = await _run_as(cmd, run_as, timeout=30)
    if not result.success:
        if result.error and "Exit code: 1" in result.error:
            return ToolResult(
                success=True,
                output="(no matches found)",
                data={"matches": 0, "pattern": pattern, "path": path},
            )
        return result

    lines = result.output.splitlines()
    if len(lines) > max_matches * (context * 2 + 3):
        lines = lines[: max_matches * (context * 2 + 3)]

    return ToolResult(
        success=True,
        output="\n".join(lines),
        data={"matches": len(lines), "pattern": pattern, "path": path},
    )


async def handle_search_admin_logs(args: dict, run_as: str) -> ToolResult:
    pattern = args.get("pattern", "")
    paths = args.get("paths") or DEFAULT_ADMIN_LOG_GLOBS
    context = max(0, min(int(args.get("context_lines", 2) or 2), 10))
    max_matches = max(1, min(int(args.get("max_matches", 100) or 100), 5000))
    ignore_case = bool(args.get("ignore_case", True))

    if not pattern:
        return ToolResult(success=False, error="No search pattern provided")

    expanded: list[str] = []
    for item in paths:
        item = str(item or "").strip()
        if not item:
            continue
        if not item.startswith("/var/log/"):
            return ToolResult(success=False, error=f"Path must be under /var/log/: {item}")
        matches = sorted(glob.glob(item))
        for match in matches:
            real_match = os.path.realpath(match)
            if real_match.startswith("/var/log/"):
                expanded.append(match)

    expanded = sorted(dict.fromkeys(expanded))
    if not expanded:
        return ToolResult(success=True, output="(no matching log files found)", data={"matches": 0, "pattern": pattern})

    cmd = [
        "/home/ai/wrappers/ai-search-logs",
        "--pattern",
        pattern,
        "--context",
        str(context),
        "--max-matches",
        str(max_matches),
    ]
    if ignore_case:
        cmd.append("--ignore-case")
    cmd.extend(expanded)

    result = await _run_as(cmd, "root", timeout=45)
    if not result.success:
        if result.error and "Exit code: 1" in result.error:
            return ToolResult(
                success=True,
                output="(no matches found)",
                data={"matches": 0, "pattern": pattern, "paths": expanded},
            )
        return result

    lines = result.output.splitlines()
    if len(lines) > max_matches * (context * 2 + 3):
        lines = lines[: max_matches * (context * 2 + 3)]

    return ToolResult(
        success=True,
        output="\n".join(lines),
        data={"matches": len(lines), "pattern": pattern, "paths": expanded},
    )


async def handle_mail_stats(args: dict, run_as: str) -> ToolResult:
    paths = args.get("paths") or DEFAULT_MAIL_LOG_GLOBS
    user = str(args.get("user", "") or "").strip()
    days = max(0, min(int(args.get("days", 0) or 0), 3650))
    limit = max(1, min(int(args.get("limit", 5) or 5), 10))

    expanded: list[str] = []
    for item in paths:
        item = str(item or "").strip()
        if not item:
            continue
        if not item.startswith("/var/log/"):
            return ToolResult(success=False, error=f"Path must be under /var/log/: {item}")
        matches = sorted(glob.glob(item))
        for match in matches:
            real_match = os.path.realpath(match)
            if real_match.startswith("/var/log/"):
                expanded.append(match)

    expanded = sorted(dict.fromkeys(expanded))
    if not expanded:
        return ToolResult(
            success=True,
            output="No matching mail log files found.",
            data={"paths": [], "matches": 0},
        )

    result = await _run_as(
        ["/home/ai/wrappers/ai-mail-stats", "--limit", str(limit), "--days", str(days)]
        + (["--user", user] if user else [])
        + expanded,
        "root",
        timeout=45,
    )
    if not result.success:
        return result

    return result


async def handle_mail_health(args: dict, run_as: str) -> ToolResult:
    paths = args.get("paths") or DEFAULT_MAIL_LOG_GLOBS
    user = str(args.get("user", "") or "").strip()
    days = max(0, min(int(args.get("days", 7) or 7), 3650))
    limit = max(1, min(int(args.get("limit", 3) or 3), 10))

    expanded: list[str] = []
    for item in paths:
        item = str(item or "").strip()
        if not item:
            continue
        if not item.startswith("/var/log/"):
            return ToolResult(success=False, error=f"Path must be under /var/log/: {item}")
        matches = sorted(glob.glob(item))
        for match in matches:
            real_match = os.path.realpath(match)
            if real_match.startswith("/var/log/"):
                expanded.append(match)

    expanded = sorted(dict.fromkeys(expanded))
    if not expanded:
        return ToolResult(
            success=True,
            output="No matching mail log files found.",
            data={"paths": [], "matches": 0},
        )

    result = await _run_as(
        ["/home/ai/wrappers/ai-mail-stats", "--mode", "health", "--limit", str(limit), "--days", str(days)]
        + (["--user", user] if user else [])
        + expanded,
        "root",
        timeout=45,
    )
    if not result.success:
        return result

    return result


async def handle_spam_abuse(args: dict, run_as: str) -> ToolResult:
    paths = args.get("paths") or DEFAULT_MAIL_LOG_GLOBS
    user = str(args.get("user", "") or "").strip()
    days = max(0, min(int(args.get("days", 7) or 7), 3650))
    limit = max(1, min(int(args.get("limit", 10) or 10), 20))

    expanded: list[str] = []
    for item in paths:
        item = str(item or "").strip()
        if not item:
            continue
        if not item.startswith("/var/log/"):
            return ToolResult(success=False, error=f"Path must be under /var/log/: {item}")
        matches = sorted(glob.glob(item))
        for match in matches:
            real_match = os.path.realpath(match)
            if real_match.startswith("/var/log/"):
                expanded.append(match)

    expanded = sorted(dict.fromkeys(expanded))
    if not expanded:
        return ToolResult(
            success=True,
            output="No matching mail log files found.",
            data={"paths": [], "matches": 0},
        )

    result = await _run_as(
        ["/home/ai/wrappers/ai-mail-stats", "--mode", "spam_abuse", "--limit", str(limit), "--days", str(days)]
        + (["--user", user] if user else [])
        + expanded,
        "root",
        timeout=45,
    )
    if not result.success:
        return result

    return result


async def handle_journalctl_query(args: dict, run_as: str) -> ToolResult:
    unit = args.get("unit")
    since = args.get("since", "today")
    until = args.get("until")
    priority = args.get("priority")
    lines = args.get("lines", 50)

    cmd = ["/home/ai/wrappers/ai-journalctl", "--lines", str(lines)]
    if unit:
        cmd += ["--unit", unit]
    if since:
        cmd += ["--since", since]
    if until:
        cmd += ["--until", until]
    if priority:
        cmd += ["--priority", priority]

    return await _run_as(cmd, "root", timeout=15)


async def _run_as(cmd: list, run_as: str, timeout: int = 30) -> ToolResult:
    """Run a command with optional sudo."""
    if run_as == "root":
        cmd = ["sudo"] + cmd

    try:
        proc = await asyncio.create_subprocess_exec(
            *cmd,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
        )
        try:
            stdout, stderr = await asyncio.wait_for(proc.communicate(), timeout=timeout)
        except asyncio.TimeoutError:
            proc.kill()
            return ToolResult(success=False, error=f"Command timed out after {timeout}s")

        if proc.returncode != 0:
            return ToolResult(
                success=False,
                error=stderr.decode().strip() or f"Exit code: {proc.returncode}",
                output=stdout.decode().strip()[:1000],
            )
        return ToolResult(success=True, output=stdout.decode().strip())
    except FileNotFoundError:
        return ToolResult(success=False, error=f"Command not found: {cmd[0]}")
    except Exception as e:
        return ToolResult(success=False, error=str(e))
