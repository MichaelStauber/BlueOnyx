# Service management tools for BlueOnyx AI Agent
# Copyright (c) 2008-2026 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2026 Team BlueOnyx, BLUEONYX.IT
# All Rights Reserved.
import asyncio
import logging
from .base import ToolDefinition, ToolResult

logger = logging.getLogger("sausalito_ai.tools.service")

# Known valid services (used for validation in write operations)
KNOWN_SERVICES = [
    "httpd", "nginx", "dovecot", "postfix", "proftpd", "named", "mariadb",
    "mysql", "clamd", "spamassassin", "cron", "rsyslog", "sshd",
    "sausalito-cce", "sausalito-cced", "sausalito-ai", "sausalito-llama",
]


def register_tools(executor):
    executor.register_tool(
        ToolDefinition(
            name="service_status",
            description="Check the status of a systemd service. Returns service status, PID, memory usage, and active time.",
            properties={
                "service": {
                    "type": "string",
                    "description": "Name of the systemd service (e.g., 'httpd', 'dovecot', 'postfix')",
                },
            },
            required=["service"],
            category="diagnostics",
        ),
        handle_service_status,
    )

    executor.register_tool(
        ToolDefinition(
            name="service_action",
            description="Start, stop, restart, or reload a systemd service. THIS IS A WRITE OPERATION and requires explicit user confirmation.",
            properties={
                "service": {
                    "type": "string",
                    "description": "Name of the systemd service",
                },
                "action": {
                    "type": "string",
                    "enum": ["start", "stop", "restart", "reload"],
                    "description": "Action to perform on the service",
                },
            },
            required=["service", "action"],
            is_write_operation=True,
            requires_password=False,
            category="actions",
        ),
        handle_service_action,
    )


async def handle_service_status(args: dict, run_as: str) -> ToolResult:
    service = args.get("service", "")
    if not service:
        return ToolResult(success=False, error="No service name provided")

    cmd = ["/home/ai/wrappers/ai-service-status", service]
    try:
        proc = await asyncio.create_subprocess_exec(
            *["sudo"] + cmd,
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.PIPE,
        )
        stdout, stderr = await asyncio.wait_for(proc.communicate(), timeout=10)

        if proc.returncode != 0:
            return ToolResult(success=False, error=stderr.decode().strip() or f"Exit: {proc.returncode}")
        return ToolResult(success=True, output=stdout.decode().strip())
    except asyncio.TimeoutError:
        return ToolResult(success=False, error="Service status command timed out")
    except Exception as e:
        return ToolResult(success=False, error=str(e))


async def handle_service_action(args: dict, run_as: str) -> ToolResult:
    """This is called AFTER user confirmation. The confirmation is handled by the agent loop."""
    service = args.get("service", "")
    action = args.get("action", "")

    # Validate inputs
    if service not in KNOWN_SERVICES and not service.startswith("base-"):
        return ToolResult(success=False, error=f"Unknown or disallowed service: {service}")
    if action not in ("start", "stop", "restart", "reload"):
        return ToolResult(success=False, error=f"Invalid action: {action} (must be start/stop/restart/reload)")

    # Execute via the ai-service-action wrapper.
    cmd = ["/home/ai/wrappers/ai-service-action", service, action]
    result = await _run_sudo(cmd)
    if not result.success:
        return result

    action_words = {
        "start": "Started",
        "stop": "Stopped",
        "restart": "Restarted",
        "reload": "Reloaded",
    }
    verb = action_words.get(action, action.capitalize())
    return ToolResult(success=True, output=f"{verb} {service}.service successfully.")


async def _run_sudo(cmd: list, timeout: int = 30) -> ToolResult:
    try:
        proc = await asyncio.create_subprocess_exec(
            *["sudo"] + cmd,
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.PIPE,
        )
        stdout, stderr = await asyncio.wait_for(proc.communicate(), timeout=timeout)
        if proc.returncode != 0:
            return ToolResult(success=False, error=stderr.decode().strip())
        return ToolResult(success=True, output=stdout.decode().strip())
    except Exception as e:
        return ToolResult(success=False, error=str(e))
