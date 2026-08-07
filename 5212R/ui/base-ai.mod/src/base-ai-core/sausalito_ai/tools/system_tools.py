"""
System Tools for Sausalito AI.
Provides tools for executing system commands with elevated privileges via ai_helper.pl
Copyright (c) 2008-2026 Michael Stauber, SOLARSPEED.NET
Copyright (c) 2008-2026 Team BlueOnyx, BLUEONYX.IT
# All Rights Reserved.
"""
from __future__ import annotations

from .base import ToolDefinition, ToolExecutor, ToolResult
import asyncio
import logging
import os
import shlex
import shutil
import stat

logger = logging.getLogger(__name__)

# Allowed base directories for wrapper scripts — no symlink escapes allowed.
_ALLOWED_WRAPPER_ROOTS = (
    "/home/ai/wrappers/",
    "/usr/sausalito/sbin/",
)


def _path_is_under_root(resolved_path: str, roots: tuple[str, ...]) -> bool:
    """Return True if resolved_path is under one of the allowed roots."""
    for root in roots:
        if resolved_path.startswith(root):
            return True
    return False


def _has_symlink_in_parents(path: str) -> bool:
    """Walk up from path and return True if any component is a symlink."""
    head = path
    while head and head != "/":
        if os.path.islink(head):
            return True
        head = os.path.dirname(head)
    return False


def _safe_realpath(path: str) -> str:
    """Resolve path via realpath, then verify no parent is a symlink.

    This prevents symlink traversal attacks where an attacker replaces
    a directory component with a symlink after the initial realpath call.
    """
    resolved = os.path.realpath(path)
    if _has_symlink_in_parents(resolved):
        # Re-resolve and compare — if they differ, a race occurred
        resolved2 = os.path.realpath(path)
        if resolved != resolved2:
            raise PermissionError(f"Symlink race detected for {path}")
    return resolved


def _resolve_command(*candidates: str) -> str | None:
    """Return the first available executable from a list of candidates."""
    for candidate in candidates:
        candidate = str(candidate or "").strip()
        if not candidate:
            continue
        if os.path.isabs(candidate):
            if os.path.exists(candidate) and os.access(candidate, os.X_OK):
                return candidate
            continue
        resolved = shutil.which(candidate)
        if resolved:
            return resolved
    return None


async def _run_command(
    candidates: tuple[str, ...],
    args: list[str],
    timeout: float = 5.0,
) -> ToolResult:
    """Run a read-only command and return captured stdout/stderr."""
    command = _resolve_command(*candidates)
    if not command:
        return ToolResult(
            success=False,
            error=f"Error: command not available ({', '.join(candidates)})",
        )

    try:
        proc = await asyncio.create_subprocess_exec(
            command,
            *args,
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.PIPE,
        )
        stdout, stderr = await asyncio.wait_for(proc.communicate(), timeout=timeout)

        if proc.returncode != 0:
            error_msg = stderr.decode("utf-8", errors="replace").strip()
            if not error_msg:
                error_msg = f"Exit code {proc.returncode}"
            return ToolResult(success=False, error=error_msg)

        output = stdout.decode("utf-8", errors="replace").strip()
        return ToolResult(success=True, output=output if output else "(No output)")
    except asyncio.TimeoutError:
        return ToolResult(success=False, error=f"Error: command timed out ({timeout:.0f}s limit)")
    except Exception as exc:
        return ToolResult(success=False, error=f"Error executing command: {exc}")


async def _collect_dns_state() -> ToolResult:
    """Return a DNS resolver summary, preferring resolvectl when present."""
    resolvectl = await _run_command(
        ("resolvectl", "/usr/bin/resolvectl", "/usr/sbin/resolvectl"),
        ["status"],
        timeout=5.0,
    )
    if resolvectl.success:
        return ToolResult(success=True, output=resolvectl.output)

    try:
        with open("/etc/resolv.conf", "r", encoding="utf-8", errors="replace") as fh:
            output = fh.read().strip()
        if output:
            return ToolResult(success=True, output=output)
    except Exception as exc:
        return ToolResult(success=False, error=f"Error reading /etc/resolv.conf: {exc}")

    return ToolResult(success=False, error=resolvectl.error or "DNS resolver information unavailable")

class RunPrivilegedCommandTool(ToolDefinition):
    """
    Tool definition for running privileged commands.
    """
    def __init__(self):
        super().__init__(
            name="run_privileged_command",
            description=(
                "Run an approved wrapper command with elevated privileges. "
                "Use this only for whitelisted wrapper scripts. "
                "Examples include /home/ai/wrappers/ai-system-info and /home/ai/wrappers/ai-uname."
            ),
            properties={
                "command": {
                    "type": "string",
                    "description": "The full command string including the wrapper path and arguments (e.g., '/home/ai/wrappers/ai-system-info')"
                }
            },
            required=["command"],
            is_write_operation=True,
            requires_password=False,
            category="advanced",
        )

class SystemUnameTool(ToolDefinition):
    """
    Tool definition for returning the exact output of `uname -a`.
    """
    def __init__(self):
        super().__init__(
            name="system_uname",
            description=(
                "Return the exact output of `uname -a`. "
                "Use this when the user asks for the system kernel and architecture output."
            ),
            properties={},
            required=[],
            is_write_operation=False,
            requires_password=False,
            category="diagnostics",
        )

class SystemMemoryTool(ToolDefinition):
    """
    Tool definition for returning memory and swap usage information.
    """
    def __init__(self):
        super().__init__(
            name="system_memory",
            description=(
                "Return memory and swap usage using free -h. "
                "Use this when the user asks how much RAM is available, how much memory is used, "
                "or wants to check system memory utilization and swap status."
            ),
            properties={},
            required=[],
            is_write_operation=False,
            requires_password=False,
            category="diagnostics",
        )


class SystemNetworkStatusTool(ToolDefinition):
    """Tool definition for a comprehensive network usage summary."""
    def __init__(self):
        super().__init__(
            name="system_network_status",
            description=(
                "Return a comprehensive network summary including interfaces, "
                "counters, routes, socket state, and bandwidth history when available. "
                "Use this for general network usage questions."
            ),
            properties={},
            required=[],
            is_write_operation=False,
            requires_password=False,
            category="diagnostics",
        )


class SystemNetworkInterfacesTool(ToolDefinition):
    """Tool definition for network interface state and addressing."""
    def __init__(self):
        super().__init__(
            name="system_network_interfaces",
            description=(
                "Return network interface names, state, and addresses using ip -brief addr."
            ),
            properties={},
            required=[],
            is_write_operation=False,
            requires_password=False,
            category="diagnostics",
        )


class SystemNetworkCountersTool(ToolDefinition):
    """Tool definition for live network byte and packet counters."""
    def __init__(self):
        super().__init__(
            name="system_network_counters",
            description=(
                "Return per-interface byte and packet counters using ip -s link or /proc/net/dev."
            ),
            properties={},
            required=[],
            is_write_operation=False,
            requires_password=False,
            category="diagnostics",
        )


class SystemNetworkRoutesTool(ToolDefinition):
    """Tool definition for routing and gateway information."""
    def __init__(self):
        super().__init__(
            name="system_network_routes",
            description=(
                "Return IPv4 and IPv6 routing information, default gateway, and route table summary."
            ),
            properties={},
            required=[],
            is_write_operation=False,
            requires_password=False,
            category="diagnostics",
        )


class SystemNetworkSocketsTool(ToolDefinition):
    """Tool definition for socket and listener summary."""
    def __init__(self):
        super().__init__(
            name="system_network_sockets",
            description=(
                "Return socket state and listener summary using ss -s and listener views."
            ),
            properties={},
            required=[],
            is_write_operation=False,
            requires_password=False,
            category="diagnostics",
        )


class SystemNetworkBandwidthTool(ToolDefinition):
    """Tool definition for historical bandwidth usage."""
    def __init__(self):
        super().__init__(
            name="system_network_bandwidth",
            description=(
                "Return historical network bandwidth usage using vnstat when installed."
            ),
            properties={},
            required=[],
            is_write_operation=False,
            requires_password=False,
            category="diagnostics",
        )


class SystemNetworkDnsTool(ToolDefinition):
    """Tool definition for DNS resolver information."""
    def __init__(self):
        super().__init__(
            name="system_network_dns",
            description=(
                "Return DNS resolver status and configured name servers."
            ),
            properties={},
            required=[],
            is_write_operation=False,
            requires_password=False,
            category="diagnostics",
        )

class SystemDiskSpaceTool(ToolDefinition):
    """
    Tool definition for returning disk usage / free space information.
    """
    def __init__(self):
        super().__init__(
            name="system_disk_space",
            description=(
                "Return free disk space and filesystem usage using df -h. "
                "Use this when the user asks how much disk space is available or wants filesystem usage."
            ),
            properties={},
            required=[],
            is_write_operation=False,
            requires_password=False,
            category="diagnostics",
        )

def _normalize_allowed_wrappers(allowed_wrappers):
    normalized = []
    seen = set()
    for wrapper in allowed_wrappers or []:
        if not isinstance(wrapper, str):
            continue
        wrapper = wrapper.strip()
        if not wrapper:
            continue
        try:
            real_path = _safe_realpath(wrapper)
        except PermissionError as exc:
            logger.warning("Skipping wrapper %s: %s", wrapper, exc)
            continue
        if real_path in seen:
            continue
        seen.add(real_path)
        normalized.append(real_path)
    return normalized


def create_run_privileged_command_handler(username: str, session_id: str, allowed_wrappers=None):
    """
    Creates an async handler function for the run_privileged_command tool.
    This closure captures the username/session_id and wrapper whitelist for authentication.
    """
    allowed_wrappers = _normalize_allowed_wrappers(allowed_wrappers)
    allowed_wrapper_set = set(allowed_wrappers)

    async def run_privileged_command(arguments: dict, run_as: str = "blueonyx_ai") -> ToolResult:
        """
        Execute a privileged command via ai_helper.pl
        
        Args:
            arguments: dict with 'command' key
            run_as: user to run as (unused here, we use the captured username/session_id)
            
        Returns:
            ToolResult with output or error
        """
        command = (arguments.get('command', '') or '').strip()
        
        if not username or not session_id:
            logger.error("User context missing in privileged command handler")
            return ToolResult(success=False, error="Error: User context missing. Cannot execute privileged command.")
        
        if not command or not command.startswith('/'):
            return ToolResult(success=False, error="Error: Command must be a full path starting with '/'")

        try:
            command_parts = shlex.split(command)
        except ValueError as e:
            return ToolResult(success=False, error=f"Error: Invalid command syntax: {e}")

        if not command_parts:
            return ToolResult(success=False, error="Error: Empty command")

        command_path = command_parts[0]
        
        # Symlink-hardened resolution: resolve, verify no symlink in parents,
        # ensure the path stays inside an allowed root directory.
        try:
            resolved_command_path = _safe_realpath(command_path)
        except PermissionError as exc:
            logger.warning("Symlink traversal blocked for command: %s (%s)", command_path, exc)
            return ToolResult(success=False, error="Error: Symlink traversal detected. Command rejected.")

        if not _path_is_under_root(resolved_command_path, _ALLOWED_WRAPPER_ROOTS):
            logger.warning("Command outside allowed roots: %s", resolved_command_path)
            return ToolResult(success=False, error="Error: Command path is outside allowed directories.")

        if not allowed_wrapper_set:
            return ToolResult(success=False, error="Error: No allowed wrapper commands are configured.")
        if resolved_command_path not in allowed_wrapper_set:
            return ToolResult(
                success=False,
                error=(
                    f"Error: Command '{command_path}' is not in the allowed wrapper whitelist."
                ),
            )
        
        # Final TOCTOU guard: stat the resolved path and verify it's a regular file
        try:
            st = os.stat(resolved_command_path)
            if not stat.S_ISREG(st.st_mode):
                logger.warning("Command path is not a regular file: %s (mode=%o)", resolved_command_path, st.st_mode)
                return ToolResult(success=False, error="Error: Command path is not a regular executable file.")
        except OSError as exc:
            logger.warning("Cannot stat command path: %s (%s)", resolved_command_path, exc)
            return ToolResult(success=False, error="Error: Cannot verify command path.")
        
        logger.info(
            "Executing privileged wrapper command: %s for user %s",
            command,
            username,
        )
        
        try:
            # Call ai_helper.pl via sudo
            # Credentials are passed via ENV (CCE_USERNAME, CCE_SESSIONID)
            env = os.environ.copy()
            env['CCE_USERNAME'] = username
            env['CCE_SESSIONID'] = session_id
            
            proc = await asyncio.create_subprocess_exec(
                'sudo', '-E', '/usr/sausalito/sbin/ai_helper.pl',
                command,
                stdout=asyncio.subprocess.PIPE,
                stderr=asyncio.subprocess.PIPE,
                env=env
            )
            
            stdout, stderr = await asyncio.wait_for(proc.communicate(), timeout=30.0)
            
            if proc.returncode != 0:
                error_msg = stderr.decode('utf-8', errors='replace').strip()
                logger.error(f"Command failed (exit {proc.returncode}): {error_msg}")
                return ToolResult(success=False, error=f"Error executing command: {error_msg}")
            
            output = stdout.decode('utf-8', errors='replace').strip()
            logger.info(f"Command output length: {len(output)}")
            return ToolResult(success=True, output=output if output else "(No output)")
            
        except asyncio.TimeoutError:
            logger.error(f"Command timed out: {command}")
            return ToolResult(success=False, error="Error: Command execution timed out (30s limit)")
        except Exception as e:
            logger.error(f"Exception executing command: {e}")
            return ToolResult(success=False, error=f"Error executing command: {str(e)}")
    
    return run_privileged_command


def create_system_uname_handler(username: str, session_id: str):
    """
    Creates a read-only handler that returns the exact output of uname -a.
    """
    async def system_uname(arguments: dict, run_as: str = "blueonyx_ai") -> ToolResult:
        try:
            proc = await asyncio.create_subprocess_exec(
                "uname",
                "-a",
                stdout=asyncio.subprocess.PIPE,
                stderr=asyncio.subprocess.PIPE,
            )

            stdout, stderr = await asyncio.wait_for(proc.communicate(), timeout=5.0)

            if proc.returncode != 0:
                error_msg = stderr.decode("utf-8", errors="replace").strip()
                logger.error("uname command failed (exit %s): %s", proc.returncode, error_msg)
                return ToolResult(success=False, error=f"Error executing uname: {error_msg}")

            output = stdout.decode("utf-8", errors="replace").strip()
            logger.info("uname output length: %d", len(output))
            return ToolResult(success=True, output=output if output else "(No output)")

        except asyncio.TimeoutError:
            logger.error("uname command timed out")
            return ToolResult(success=False, error="Error: uname execution timed out (5s limit)")
        except Exception as e:
            logger.error("Exception executing uname: %s", e)
            return ToolResult(success=False, error=f"Error executing uname: {str(e)}")

    return system_uname


def create_system_disk_space_handler(username: str, session_id: str):
    """
    Creates a read-only handler that returns free disk space and filesystem usage.
    """
    async def system_disk_space(arguments: dict, run_as: str = "blueonyx_ai") -> ToolResult:
        try:
            proc = await asyncio.create_subprocess_exec(
                "df",
                "-h",
                stdout=asyncio.subprocess.PIPE,
                stderr=asyncio.subprocess.PIPE,
            )

            stdout, stderr = await asyncio.wait_for(proc.communicate(), timeout=5.0)

            if proc.returncode != 0:
                error_msg = stderr.decode("utf-8", errors="replace").strip()
                logger.error("df command failed (exit %s): %s", proc.returncode, error_msg)
                return ToolResult(success=False, error=f"Error executing df: {error_msg}")

            output = stdout.decode("utf-8", errors="replace").strip()
            logger.info("df output length: %d", len(output))
            return ToolResult(success=True, output=output if output else "(No output)")

        except asyncio.TimeoutError:
            logger.error("df command timed out")
            return ToolResult(success=False, error="Error: df execution timed out (5s limit)")
        except Exception as e:
            logger.error("Exception executing df: %s", e)
            return ToolResult(success=False, error=f"Error executing df: {str(e)}")

    return system_disk_space

def create_system_memory_handler(username: str, session_id: str):
    """
    Creates a read-only handler that returns memory and swap usage via free -h.
    """
    async def system_memory(arguments: dict, run_as: str = "blueonyx_ai") -> ToolResult:
        try:
            proc = await asyncio.create_subprocess_exec(
                "free",
                "-h",
                stdout=asyncio.subprocess.PIPE,
                stderr=asyncio.subprocess.PIPE,
            )

            stdout, stderr = await asyncio.wait_for(proc.communicate(), timeout=5.0)

            if proc.returncode != 0:
                error_msg = stderr.decode("utf-8", errors="replace").strip()
                logger.error("free command failed (exit %s): %s", proc.returncode, error_msg)
                return ToolResult(success=False, error=f"Error executing free: {error_msg}")

            output = stdout.decode("utf-8", errors="replace").strip()
            logger.info("free output length: %d", len(output))
            return ToolResult(success=True, output=output if output else "(No output)")

        except asyncio.TimeoutError:
            logger.error("free command timed out")
            return ToolResult(success=False, error="Error: free execution timed out (5s limit)")
        except Exception as e:
            logger.error("Exception executing free: %s", e)
            return ToolResult(success=False, error=f"Error executing free: {str(e)}")

    return system_memory


def create_system_network_status_handler(username: str, session_id: str):
    """Creates a read-only handler that returns a comprehensive network summary."""
    async def system_network_status(arguments: dict, run_as: str = "blueonyx_ai") -> ToolResult:
        sections: list[str] = []
        errors: list[str] = []

        interface_result = await _run_command(("ip", "/usr/sbin/ip", "/sbin/ip"), ["-brief", "addr"], timeout=5.0)
        if interface_result.success:
            sections.append("Interfaces:\n" + interface_result.output)
        else:
            errors.append(f"interfaces: {interface_result.error}")

        counters_result = await _run_command(("ip", "/usr/sbin/ip", "/sbin/ip"), ["-s", "link"], timeout=5.0)
        if counters_result.success:
            sections.append("Live counters:\n" + counters_result.output)
        else:
            errors.append(f"counters: {counters_result.error}")

        routes_result = await _run_command(("ip", "/usr/sbin/ip", "/sbin/ip"), ["route", "show"], timeout=5.0)
        if routes_result.success:
            sections.append("IPv4 routes:\n" + routes_result.output)
        else:
            errors.append(f"routes: {routes_result.error}")

        routes_v6_result = await _run_command(("ip", "/usr/sbin/ip", "/sbin/ip"), ["-6", "route", "show"], timeout=5.0)
        if routes_v6_result.success and routes_v6_result.output != "(No output)":
            sections.append("IPv6 routes:\n" + routes_v6_result.output)

        sockets_result = await _run_command(("ss", "/usr/sbin/ss", "/sbin/ss"), ["-s"], timeout=5.0)
        if sockets_result.success:
            sections.append("Socket summary:\n" + sockets_result.output)
        else:
            errors.append(f"sockets: {sockets_result.error}")

        dns_result = await _collect_dns_state()
        if dns_result.success:
            sections.append("DNS resolvers:\n" + dns_result.output)
        else:
            errors.append(f"dns: {dns_result.error}")

        listeners_result = await _run_command(("ss", "/usr/sbin/ss", "/sbin/ss"), ["-ltn"], timeout=5.0)
        if listeners_result.success:
            sections.append("TCP listeners:\n" + listeners_result.output)

        bandwidth_result = await _run_command(("vnstat", "/usr/bin/vnstat", "/usr/sbin/vnstat"), ["-d"], timeout=8.0)
        if bandwidth_result.success:
            sections.append("Bandwidth history (daily):\n" + bandwidth_result.output)
        else:
            errors.append(f"bandwidth: {bandwidth_result.error}")

        if not sections:
            return ToolResult(
                success=False,
                error="Unable to gather any network information: " + "; ".join(errors) if errors else "Unable to gather any network information.",
            )

        output_parts = [
            "Network status summary:",
            *sections,
        ]
        if errors:
            output_parts.append("Unavailable data:\n- " + "\n- ".join(errors))
        return ToolResult(success=True, output="\n\n".join(output_parts))

    return system_network_status


def create_system_network_dns_handler(username: str, session_id: str):
    """Creates a read-only handler for DNS resolver information."""
    async def system_network_dns(arguments: dict, run_as: str = "blueonyx_ai") -> ToolResult:
        return await _collect_dns_state()

    return system_network_dns


def create_system_network_interfaces_handler(username: str, session_id: str):
    """Creates a read-only handler for interface addresses and link state."""
    async def system_network_interfaces(arguments: dict, run_as: str = "blueonyx_ai") -> ToolResult:
        return await _run_command(("ip", "/usr/sbin/ip", "/sbin/ip"), ["-brief", "addr"], timeout=5.0)

    return system_network_interfaces


def create_system_network_counters_handler(username: str, session_id: str):
    """Creates a read-only handler for per-interface byte and packet counters."""
    async def system_network_counters(arguments: dict, run_as: str = "blueonyx_ai") -> ToolResult:
        result = await _run_command(("ip", "/usr/sbin/ip", "/sbin/ip"), ["-s", "link"], timeout=5.0)
        if result.success:
            return result

        # Fallback to /proc/net/dev when ip is unavailable.
        try:
            with open("/proc/net/dev", "r", encoding="utf-8", errors="replace") as fh:
                output = fh.read().strip()
            if output:
                return ToolResult(success=True, output=output)
            return ToolResult(success=False, error="Error: /proc/net/dev is empty")
        except Exception as exc:
            return ToolResult(success=False, error=f"Error reading /proc/net/dev: {exc}")

    return system_network_counters


def create_system_network_routes_handler(username: str, session_id: str):
    """Creates a read-only handler for IPv4 and IPv6 routing tables."""
    async def system_network_routes(arguments: dict, run_as: str = "blueonyx_ai") -> ToolResult:
        ipv4 = await _run_command(("ip", "/usr/sbin/ip", "/sbin/ip"), ["route", "show"], timeout=5.0)
        ipv6 = await _run_command(("ip", "/usr/sbin/ip", "/sbin/ip"), ["-6", "route", "show"], timeout=5.0)

        sections: list[str] = []
        errors: list[str] = []
        if ipv4.success:
            sections.append("IPv4 routes:\n" + ipv4.output)
        else:
            errors.append(f"IPv4 routes: {ipv4.error}")
        if ipv6.success and ipv6.output != "(No output)":
            sections.append("IPv6 routes:\n" + ipv6.output)
        elif not ipv6.success:
            errors.append(f"IPv6 routes: {ipv6.error}")

        if not sections:
            return ToolResult(success=False, error="; ".join(errors) if errors else "No routing information available")

        output = "\n\n".join(sections)
        if errors:
            output += "\n\nUnavailable data:\n- " + "\n- ".join(errors)
        return ToolResult(success=True, output=output)

    return system_network_routes


def create_system_network_sockets_handler(username: str, session_id: str):
    """Creates a read-only handler for socket state and listener summaries."""
    async def system_network_sockets(arguments: dict, run_as: str = "blueonyx_ai") -> ToolResult:
        summary = await _run_command(("ss", "/usr/sbin/ss", "/sbin/ss"), ["-s"], timeout=5.0)
        listeners = await _run_command(("ss", "/usr/sbin/ss", "/sbin/ss"), ["-ltn"], timeout=5.0)
        udp = await _run_command(("ss", "/usr/sbin/ss", "/sbin/ss"), ["-lun"], timeout=5.0)

        sections: list[str] = []
        errors: list[str] = []
        if summary.success:
            sections.append("Socket summary:\n" + summary.output)
        else:
            errors.append(f"summary: {summary.error}")
        if listeners.success:
            sections.append("TCP listeners:\n" + listeners.output)
        else:
            errors.append(f"listeners: {listeners.error}")
        if udp.success:
            sections.append("UDP listeners:\n" + udp.output)
        else:
            errors.append(f"udp: {udp.error}")

        if not sections:
            return ToolResult(success=False, error="; ".join(errors) if errors else "No socket information available")

        output = "\n\n".join(sections)
        if errors:
            output += "\n\nUnavailable data:\n- " + "\n- ".join(errors)
        return ToolResult(success=True, output=output)

    return system_network_sockets


def create_system_network_bandwidth_handler(username: str, session_id: str):
    """Creates a read-only handler for historical bandwidth usage."""
    async def system_network_bandwidth(arguments: dict, run_as: str = "blueonyx_ai") -> ToolResult:
        daily = await _run_command(("vnstat", "/usr/bin/vnstat", "/usr/sbin/vnstat"), ["-d"], timeout=8.0)
        hourly = await _run_command(("vnstat", "/usr/bin/vnstat", "/usr/sbin/vnstat"), ["-h"], timeout=8.0)

        sections: list[str] = []
        errors: list[str] = []
        if daily.success:
            sections.append("Daily bandwidth:\n" + daily.output)
        else:
            errors.append(f"daily: {daily.error}")
        if hourly.success:
            sections.append("Hourly bandwidth:\n" + hourly.output)
        else:
            errors.append(f"hourly: {hourly.error}")

        if not sections:
            return ToolResult(success=False, error="; ".join(errors) if errors else "vnstat is not available")

        output = "\n\n".join(sections)
        if errors:
            output += "\n\nUnavailable data:\n- " + "\n- ".join(errors)
        return ToolResult(success=True, output=output)

    return system_network_bandwidth


def register_tools(executor: ToolExecutor):
    """
    Register system tools with the ToolExecutor.
    Note: The actual handler is replaced per-request in ai_service.py
    with the correct username/session_id context.
    """
    tool = RunPrivilegedCommandTool()
    uname_tool = SystemUnameTool()
    disk_tool = SystemDiskSpaceTool()
    network_status_tool = SystemNetworkStatusTool()
    network_interfaces_tool = SystemNetworkInterfacesTool()
    network_counters_tool = SystemNetworkCountersTool()
    network_routes_tool = SystemNetworkRoutesTool()
    network_sockets_tool = SystemNetworkSocketsTool()
    network_bandwidth_tool = SystemNetworkBandwidthTool()
    network_dns_tool = SystemNetworkDnsTool()
    
    # Register with a dummy handler that returns an error
    # The actual handler is set per-request via executor.register_tool()
    async def dummy_handler(arguments: dict, run_as: str = "blueonyx_ai") -> ToolResult:
        return ToolResult(
            success=False, 
            error="Error: Tool not properly initialized with user context. Please try again."
        )
    
    executor.register_tool(tool, dummy_handler)
    logger.info("Registered run_privileged_command tool (handler to be set per-request)")

    async def dummy_uname_handler(arguments: dict, run_as: str = "blueonyx_ai") -> ToolResult:
        return ToolResult(
            success=False,
            error="Error: Tool not properly initialized with user context. Please try again.",
        )

    executor.register_tool(uname_tool, dummy_uname_handler)
    logger.info("Registered system_uname tool (handler to be set per-request)")

    async def dummy_disk_handler(arguments: dict, run_as: str = "blueonyx_ai") -> ToolResult:
        return ToolResult(
            success=False,
            error="Error: Tool not properly initialized with user context. Please try again.",
        )

    executor.register_tool(disk_tool, dummy_disk_handler)
    memory_tool = SystemMemoryTool()
    
    async def dummy_memory_handler(arguments: dict, run_as: str = "blueonyx_ai") -> ToolResult:
        return ToolResult(
            success=False,
            error="Error: Tool not properly initialized with user context. Please try again.",
        )

    executor.register_tool(memory_tool, dummy_memory_handler)
    logger.info("Registered system_memory tool (handler to be set per-request)")

    async def dummy_network_handler(arguments: dict, run_as: str = "blueonyx_ai") -> ToolResult:
        return ToolResult(
            success=False,
            error="Error: Tool not properly initialized with user context. Please try again.",
        )

    executor.register_tool(network_status_tool, dummy_network_handler)
    logger.info("Registered system_network_status tool (handler to be set per-request)")

    executor.register_tool(network_interfaces_tool, dummy_network_handler)
    logger.info("Registered system_network_interfaces tool (handler to be set per-request)")

    executor.register_tool(network_counters_tool, dummy_network_handler)
    logger.info("Registered system_network_counters tool (handler to be set per-request)")

    executor.register_tool(network_routes_tool, dummy_network_handler)
    logger.info("Registered system_network_routes tool (handler to be set per-request)")

    executor.register_tool(network_sockets_tool, dummy_network_handler)
    logger.info("Registered system_network_sockets tool (handler to be set per-request)")

    executor.register_tool(network_bandwidth_tool, dummy_network_handler)
    logger.info("Registered system_network_bandwidth tool (handler to be set per-request)")

    executor.register_tool(network_dns_tool, dummy_network_handler)
    logger.info("Registered system_network_dns tool (handler to be set per-request)")

    logger.info("Registered system_disk_space tool (handler to be set per-request)")
