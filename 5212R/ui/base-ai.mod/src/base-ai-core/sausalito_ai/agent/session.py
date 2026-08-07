"""
session.py -- Session management for BlueOnyx AI service.

Copyright (c) 2008-2026 Michael Stauber, SOLARSPEED.NET
Copyright (c) 2008-2026 Team BlueOnyx, BLUEONYX.IT
All Rights Reserved.

Sessions are stored as JSON files on disk under
/var/lib/sausalito/ai/sessions/<session_id>.json
"""

from __future__ import annotations

import asyncio
import functools
import json
import logging
import os
import shutil
import time
from pathlib import Path
from typing import Any, Optional

logger = logging.getLogger("sausalito_ai.agent.session")

SESSION_DIR = Path("/var/lib/sausalito/ai/sessions")
SESSION_TTL_DAYS = 7
LOCK_TIMEOUT = 5.0  # seconds


async def _to_thread(func, *args, **kwargs):
    """Python 3.8-compatible equivalent of asyncio.to_thread()."""
    loop = asyncio.get_running_loop()
    call = functools.partial(func, *args, **kwargs)
    return await loop.run_in_executor(None, call)


class SessionManager:
    """Manages chat sessions persisted as JSON files.

    Each session contains:
        session_id: str
        created: float (timestamp)
        updated: float (timestamp)
        messages: list[dict]  -- conversation history in OpenAI format
        config: dict          -- ephemeral runtime config
        pending_confirmation: Optional[dict] -- pending write tool confirmation
    """

    def __init__(self, session_dir: Optional[Path] = None) -> None:
        self.session_dir = session_dir or SESSION_DIR
        self.session_dir.mkdir(parents=True, exist_ok=True)
        self._locks: dict[str, asyncio.Lock] = {}
        logger.info("SessionManager initialized: %s", self.session_dir)

    def _path(self, session_id: str) -> Path:
        """Return the filesystem path for a session."""
        # Sanitize: only allow safe characters
        safe_id = "".join(c for c in session_id if c.isalnum() or c in "-_.")
        if not safe_id:
            safe_id = "default"
        return self.session_dir / f"{safe_id}.json"

    def _lock(self, session_id: str) -> asyncio.Lock:
        """Get or create an asyncio lock for this session."""
        if session_id not in self._locks:
            self._locks[session_id] = asyncio.Lock()
        return self._locks[session_id]

    async def get(self, session_id: str) -> dict[str, Any]:
        """Load a session from disk. Returns a default session if not found."""
        path = self._path(session_id)
        async with self._lock(session_id):
            if path.exists():
                try:
                    data = await _to_thread(path.read_text, encoding="utf-8")
                    session = json.loads(data)
                    logger.debug("Loaded session: %s", session_id)
                    return session
                except (json.JSONDecodeError, OSError) as e:
                    logger.warning("Corrupted session %s: %s. Creating new.", session_id, e)

            # Default session
            now = time.time()
            return {
                "session_id": session_id,
                "created": now,
                "updated": now,
                "messages": [],
                "config": {},
                "pending_confirmation": None,
            }

    async def save(self, session_id: str, session: dict[str, Any]) -> None:
        """Persist a session to disk."""
        path = self._path(session_id)
        session["updated"] = time.time()
        async with self._lock(session_id):
            try:
                await _to_thread(
                    path.write_text,
                    json.dumps(session, indent=2, ensure_ascii=False),
                    encoding="utf-8",
                )
                logger.debug("Saved session: %s", session_id)
            except OSError as e:
                logger.error("Failed to save session %s: %s", session_id, e)
                raise

    async def delete(self, session_id: str) -> None:
        """Remove a session from disk."""
        path = self._path(session_id)
        async with self._lock(session_id):
            if path.exists():
                try:
                    path.unlink()
                    logger.info("Deleted session: %s", session_id)
                except OSError as e:
                    logger.error("Failed to delete session %s: %s", session_id, e)
            if session_id in self._locks:
                del self._locks[session_id]

    async def cleanup_old(self) -> int:
        """Remove sessions older than SESSION_TTL_DAYS.

        Returns the number of removed sessions.
        """
        cutoff = time.time() - (SESSION_TTL_DAYS * 86400)
        removed = 0
        try:
            for entry in os.scandir(self.session_dir):
                if entry.is_file() and entry.name.endswith(".json"):
                    try:
                        stat = entry.stat()
                        if stat.st_mtime < cutoff:
                            os.unlink(entry.path)
                            removed += 1
                            logger.info("Cleaned up old session: %s", entry.name)
                    except OSError:
                        continue
        except OSError as e:
            logger.error("Session cleanup error: %s", e)
        return removed

    def get_active_count(self, idle_timeout: int = 300) -> int:
        """Return number of sessions with activity within idle_timeout seconds."""
        cutoff = time.time() - idle_timeout
        count = 0
        try:
            for entry in os.scandir(self.session_dir):
                if entry.is_file() and entry.name.endswith(".json"):
                    try:
                        stat = entry.stat()
                        if stat.st_mtime >= cutoff:
                            count += 1
                    except OSError:
                        continue
        except OSError:
            pass
        return count

    async def add_message(self, session_id: str, role: str, content: Any) -> None:
        """Append a message to the session's conversation history."""
        session = await self.get(session_id)
        session["messages"].append({"role": role, "content": content})
        await self.save(session_id, session)

    async def set_pending_confirmation(
        self, session_id: str, tool_call_id: str, tool: str, args: dict, reason: str
    ) -> None:
        """Store a pending tool confirmation request."""
        session = await self.get(session_id)
        session["pending_confirmation"] = {
            "tool_call_id": tool_call_id,
            "tool": tool,
            "args": args,
            "reason": reason,
            "timestamp": time.time(),
        }
        await self.save(session_id, session)

    async def clear_pending_confirmation(self, session_id: str) -> None:
        """Clear the pending confirmation for a session."""
        session = await self.get(session_id)
        session["pending_confirmation"] = None
        await self.save(session_id, session)
