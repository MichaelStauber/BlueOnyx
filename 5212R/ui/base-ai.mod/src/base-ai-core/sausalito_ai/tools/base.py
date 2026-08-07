# Base tool classes for BlueOnyx AI Agent
# Copyright (c) 2008-2026 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2026 Team BlueOnyx, BLUEONYX.IT
# All Rights Reserved.
from __future__ import annotations

import asyncio
import logging
import subprocess
import json
import shlex
from dataclasses import dataclass, field
from typing import Any, Optional

from .tool_context import reset_tool_context, set_tool_context

logger = logging.getLogger("sausalito_ai.tools")


@dataclass
class ToolDefinition:
    name: str
    description: str
    properties: dict[str, Any] = field(default_factory=dict)
    required: list[str] = field(default_factory=list)
    is_write_operation: bool = False
    requires_password: bool = False
    category: str = "read_only"

    def to_dict(self) -> dict[str, Any]:
        """Convert to OpenAI-compatible tool definition dict."""
        return {
            "type": "function",
            "function": {
                "name": self.name,
                "description": self.description,
                "parameters": {
                    "type": "object",
                    "properties": self.properties,
                    "required": self.required,
                    "additionalProperties": False,
                },
            },
        }

    def validate_arguments(self, arguments: Any) -> tuple[bool, str]:
        """Validate tool arguments against the declared schema."""
        if arguments is None:
            arguments = {}

        if not isinstance(arguments, dict):
            return False, "Tool arguments must be a JSON object"

        unexpected = sorted(set(arguments.keys()) - set(self.properties.keys()))
        if unexpected:
            return False, "Unexpected argument(s): " + ", ".join(unexpected)

        missing = [key for key in self.required if key not in arguments]
        if missing:
            return False, "Missing required argument(s): " + ", ".join(missing)

        for name, schema in self.properties.items():
            if name not in arguments:
                continue
            ok, message = self._validate_value(name, arguments[name], schema)
            if not ok:
                return False, message

        return True, ""

    def _validate_value(self, name: str, value: Any, schema: dict[str, Any]) -> tuple[bool, str]:
        expected_type = schema.get("type")
        if expected_type == "string":
            if not isinstance(value, str):
                return False, f"Argument '{name}' must be a string"
        elif expected_type == "integer":
            if not isinstance(value, int) or isinstance(value, bool):
                return False, f"Argument '{name}' must be an integer"
        elif expected_type == "number":
            if not isinstance(value, (int, float)) or isinstance(value, bool):
                return False, f"Argument '{name}' must be a number"
        elif expected_type == "boolean":
            if not isinstance(value, bool):
                return False, f"Argument '{name}' must be a boolean"
        elif expected_type == "array":
            if not isinstance(value, list):
                return False, f"Argument '{name}' must be an array"
        elif expected_type == "object":
            if not isinstance(value, dict):
                return False, f"Argument '{name}' must be an object"

        enum_values = schema.get("enum")
        if enum_values is not None and value not in enum_values:
            return False, f"Argument '{name}' must be one of: {', '.join(map(str, enum_values))}"

        return True, ""


@dataclass
class ToolResult:
    success: bool = True
    output: str = ""
    error: str = ""
    data: Any = None


class ToolExecutor:
    """Executes tool calls with UID-switching support."""

    def __init__(self):
        self._tools: dict[str, ToolDefinition] = {}
        self._handlers: dict[str, callable] = {}
        self._register_tools()

    def _register_tools(self):
        """Register all available tools and their handlers."""
        from .log_tools import register_tools as reg_log
        from .file_tools import register_tools as reg_file
        from .diagnostic_tools import register_tools as reg_diag
        from .knowledge_tools import register_tools as reg_knowledge
        from .system_tools import register_tools as reg_sys
        from .service_tools import register_tools as reg_svc

        reg_log(self)
        reg_file(self)
        reg_diag(self)
        reg_knowledge(self)
        reg_sys(self)
        reg_svc(self)

    def register_tool(self, defn: ToolDefinition, handler: callable):
        self._tools[defn.name] = defn
        self._handlers[defn.name] = handler

    def get_tool_definitions(
        self,
        enabled: bool = True,
        allowed_categories: list[str] | None = None,
    ) -> list[ToolDefinition]:
        if not enabled:
            return []
        if not allowed_categories:
            return list(self._tools.values())
        allowed = {str(category).strip() for category in allowed_categories if str(category).strip()}
        return [
            tool
            for tool in self._tools.values()
            if tool.category in allowed
        ]

    def get_tool(self, name: str) -> ToolDefinition:
        return self._tools.get(name)

    async def execute(
        self,
        tool_name: str,
        arguments: dict,
        run_as: str = "blueonyx_ai",
        context: dict[str, Any] | None = None,
    ) -> ToolResult:
        handler = self._handlers.get(tool_name)
        if not handler:
            return ToolResult(success=False, error=f"Unknown tool: {tool_name}")

        defn = self._tools.get(tool_name)
        if defn.is_write_operation:
            logger.info(f"Write operation {tool_name} requested (run_as={run_as})")

        ok, error = defn.validate_arguments(arguments)
        if not ok:
            logger.warning("Rejected invalid arguments for tool %s: %s", tool_name, error)
            return ToolResult(success=False, error=error)

        token = set_tool_context(context)
        try:
            if asyncio.iscoroutinefunction(handler):
                result = await handler(arguments, run_as)
            else:
                result = handler(arguments, run_as)
            return result
        except Exception as e:
            logger.error(f"Tool {tool_name} failed: {e}", exc_info=True)
            return ToolResult(success=False, error=str(e))
        finally:
            reset_tool_context(token)

    async def _run_as_user(self, cmd: list[str], run_as: str, timeout: int = 30) -> ToolResult:
        """Run a command as a specified user via sudo -u #<uid>."""
        if run_as and run_as != "blueonyx_ai" and not run_as.startswith("blueonyx"):
            cmd = ["sudo", "-u", f"#{run_as}"] + cmd
        elif run_as == "root":
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
                    output=stdout.decode().strip(),
                )
            return ToolResult(success=True, output=stdout.decode().strip())
        except FileNotFoundError:
            return ToolResult(success=False, error=f"Command not found: {cmd[0]}")
        except Exception as e:
            return ToolResult(success=False, error=str(e))
