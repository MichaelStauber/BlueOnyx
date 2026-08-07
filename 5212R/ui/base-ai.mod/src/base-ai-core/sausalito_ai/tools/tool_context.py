"""Per-request tool execution context for BlueOnyx AI tools."""

from __future__ import annotations

from contextvars import ContextVar
from typing import Any

_TOOL_CONTEXT: ContextVar[dict[str, Any]] = ContextVar("sausalito_ai_tool_context", default={})


def set_tool_context(context: dict[str, Any] | None) -> object:
    """Set the current tool execution context and return a reset token."""
    return _TOOL_CONTEXT.set(dict(context or {}))


def reset_tool_context(token: object) -> None:
    """Restore the previous tool execution context."""
    _TOOL_CONTEXT.reset(token)


def get_tool_context() -> dict[str, Any]:
    """Return the current tool execution context."""
    value = _TOOL_CONTEXT.get()
    return value if isinstance(value, dict) else {}
