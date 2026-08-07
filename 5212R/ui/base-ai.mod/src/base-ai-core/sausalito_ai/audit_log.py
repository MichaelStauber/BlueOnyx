"""
audit_log.py -- Structured syslog audit logging for BlueOnyx AI.

Logs every security-relevant event to the local syslog daemon
for central collection, log rotation, and SIEM integration.

Facility: syslog.LOG_LOCAL6 (configurable)
Priority:  syslog.LOG_INFO for normal events,
           syslog.LOG_WARNING for errors / blocked operations,
           syslog.LOG_INFO for confirmation requests.

Format:   JSON with fields:
    event   str    -- event type (user_query, tool_execution,
                      model_response, confirmation_request,
                      confirmation_granted, confirmation_denied, error)
    ts      float  -- Unix timestamp with fractional seconds
    sid     str    -- session_id (anonymized identifier)
    user    str    -- run_as identity (e.g. "root", "admin")
    model   str    -- model identifier (if known)
    data    dict   -- event-specific payload

Fallback: If the syslog module is unavailable or /dev/log cannot be reached,
          the audit event is dropped rather than routed into the journal.
"""

from __future__ import annotations

import json
import logging
import os
import socket
import sys
import time

logger = logging.getLogger("sausalito_ai.audit")

# ---------------------------------------------------------------------------
# Syslog helpers
# ---------------------------------------------------------------------------

def _syslog_available() -> bool:
    """Check whether the standard syslog module is functional."""
    try:
        import syslog
        syslog.openlog("blueonyx-ai", syslog.LOG_PID, syslog.LOG_LOCAL6)
        syslog.closelog()
        return True
    except Exception:
        return False


# Cache facility / priority mapping once
_SYSLOG_PRI_MAP = {
    "debug": 7,
    "info": 6,
    "notice": 5,
    "warning": 4,
    "err": 3,
}

# RFC 3164 / BSD syslog: facility * 8 + priority
# LOCAL6 = 22  (chosen for AI audit trail to keep it separate from mail/auth)
_FACILITY_LOCAL6 = 22


def _format_rfc3164(
    facility: int,
    priority: int,
    tag: str,
    message: str,
) -> bytes:
    """Build a minimal RFC 3164 datagram payload.

    We do not include hostname or timestamp because the syslog daemon
    adds those automatically.  We just send:  <PRI>TAG: MESSAGE
    """
    pri = facility * 8 + priority
    # truncate message to avoid oversized UDP datagram
    if len(message) > 65535:
        message = message[:65530] + " ..."
    return f"<{pri}>{tag}: {message}".encode("utf-8", errors="replace")


class AuditLogger:
    """Structured audit logger that writes JSON payloads to syslog."""

    def __init__(
        self,
        tag: str = "blueonyx-ai",
        facility: int = _FACILITY_LOCAL6,
    ) -> None:
        self._tag = tag
        self._facility = facility
        self._syslog_ok = _syslog_available()
        self._devlog_ok = self._devlog_available()
        if not self._syslog_ok and not self._devlog_ok:
            logger.warning("AuditLogger: neither syslog module nor /dev/log available; audit disabled")

    def _devlog_available(self) -> bool:
        return os.path.exists("/dev/log")

    # ------------------------------------------------------------------
    # Internal write
    # ------------------------------------------------------------------
    def _write(self, priority_name: str, payload: dict) -> None:
        priority = _SYSLOG_PRI_MAP.get(priority_name, 6)
        line = json.dumps(payload, separators=(",", ":"), ensure_ascii=False)

        # 1. Try standard syslog module first
        if self._syslog_ok:
            try:
                import syslog
                syslog.openlog(self._tag, syslog.LOG_PID, syslog.LOG_LOCAL6)
                syslog.syslog(priority, line)
                syslog.closelog()
                return
            except Exception as exc:
                self._syslog_ok = False
                logger.debug("syslog module failed (%s), trying /dev/log fallback", exc)

        # 2. Fallback: direct /dev/log datagram
        if self._devlog_ok:
            try:
                data = _format_rfc3164(self._facility, priority, self._tag, line)
                with socket.socket(socket.AF_UNIX, socket.SOCK_DGRAM) as sock:
                    sock.connect("/dev/log")
                    sock.send(data)
                return
            except Exception as exc:
                self._devlog_ok = False
                logger.warning("AuditLogger /dev/log fallback failed: %s", exc)

        # 3. Last resort: drop the audit event rather than spamming the journal.
        # The service itself already logs operational errors separately.
        return

    # ------------------------------------------------------------------
    # Public event loggers
    # ------------------------------------------------------------------
    def log_user_query(
        self,
        session_id: str,
        user: str,
        message: str,
        model: str = "",
    ) -> None:
        self._write("info", {
            "event": "user_query",
            "ts": time.time(),
            "sid": session_id,
            "user": user,
            "model": model,
            "data": {
                "msg_len": len(message),
                "msg_preview": message[:200],
            },
        })

    def log_tool_execution(
        self,
        session_id: str,
        user: str,
        tool_name: str,
        tool_args: dict,
        success: bool,
        duration_ms: float,
        model: str = "",
    ) -> None:
        # Sanitise args: drop any potentially sensitive keys (password, key, token, secret)
        safe_args = self._redact_sensitive(tool_args)
        self._write("info" if success else "warning", {
            "event": "tool_execution",
            "ts": time.time(),
            "sid": session_id,
            "user": user,
            "model": model,
            "data": {
                "tool": tool_name,
                "args": safe_args,
                "success": success,
                "duration_ms": round(duration_ms, 2),
            },
        })

    def log_model_response(
        self,
        session_id: str,
        user: str,
        model: str,
        response_len: int,
        iterations: int = 0,
    ) -> None:
        self._write("info", {
            "event": "model_response",
            "ts": time.time(),
            "sid": session_id,
            "user": user,
            "model": model,
            "data": {
                "response_len": response_len,
                "iterations": iterations,
            },
        })

    def log_confirmation_request(
        self,
        session_id: str,
        user: str,
        tool_name: str,
        tool_args: dict,
        model: str = "",
    ) -> None:
        safe_args = self._redact_sensitive(tool_args)
        self._write("notice", {
            "event": "confirmation_request",
            "ts": time.time(),
            "sid": session_id,
            "user": user,
            "model": model,
            "data": {
                "tool": tool_name,
                "args": safe_args,
            },
        })

    def log_confirmation_granted(
        self,
        session_id: str,
        user: str,
        tool_name: str,
        model: str = "",
    ) -> None:
        self._write("info", {
            "event": "confirmation_granted",
            "ts": time.time(),
            "sid": session_id,
            "user": user,
            "model": model,
            "data": {"tool": tool_name},
        })

    def log_confirmation_denied(
        self,
        session_id: str,
        user: str,
        tool_name: str,
        model: str = "",
    ) -> None:
        self._write("warning", {
            "event": "confirmation_denied",
            "ts": time.time(),
            "sid": session_id,
            "user": user,
            "model": model,
            "data": {"tool": tool_name},
        })

    def log_error(
        self,
        session_id: str,
        user: str,
        error_type: str,
        message: str,
        model: str = "",
    ) -> None:
        self._write("warning", {
            "event": "error",
            "ts": time.time(),
            "sid": session_id,
            "user": user,
            "model": model,
            "data": {
                "error_type": error_type,
                "message": message[:500],
            },
        })

    # ------------------------------------------------------------------
    # Helpers
    # ------------------------------------------------------------------
    @staticmethod
    def _redact_sensitive(args: dict) -> dict:
        """Return a copy of args with likely-sensitive values redacted."""
        out: dict = {}
        for k, v in args.items():
            key_lower = str(k).lower()
            if any(s in key_lower for s in ("password", "secret", "token", "key", "api_key", "auth")):
                out[k] = "***REDACTED***"
            elif isinstance(v, dict):
                out[k] = AuditLogger._redact_sensitive(v)
            else:
                out[k] = v
        return out
