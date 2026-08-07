# File inspection tools for BlueOnyx AI Agent.
#
# These tools are intentionally read-only and restricted to approved roots.
#
# Copyright (c) 2008-2026 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2026 Team BlueOnyx, BLUEONYX.IT
# All Rights Reserved.

from __future__ import annotations

import fnmatch
import hashlib
import logging
import os
import pwd
import grp
import stat
import time
from pathlib import Path
from typing import Any

from .base import ToolDefinition, ToolResult
from .tool_context import get_tool_context

logger = logging.getLogger("sausalito_ai.tools.file")


ALLOWED_ROOTS = (
    "/home/sites/",
    "/home/.sites/",
    "/var/log/",
)

SAUSALITO_DENY_ROOTS = (
    "/usr/sausalito/capcache/",
    "/usr/sausalito/codb/",
    "/usr/sausalito/license/",
    "/usr/sausalito/sessions/",
)

DIAGNOSTIC_PROFILES = {"guided", "investigative", "freeform"}


def _is_under_root(real_path: str, root: str) -> bool:
    root = os.path.realpath(root).rstrip("/")
    real_path = os.path.realpath(real_path)
    return real_path == root or real_path.startswith(root + "/")


def _get_model_profile() -> str:
    context = get_tool_context()
    profile = str(context.get("model_profile", "restricted") or "restricted").strip().lower()
    if profile not in DIAGNOSTIC_PROFILES and profile != "restricted":
        return "restricted"
    return profile


def _path_allowed_for_profile(path: str) -> tuple[bool, str]:
    real_path = os.path.realpath(path)
    profile = _get_model_profile()

    if _is_under_root(real_path, "/home/ai/"):
        return False, "Path is denied: /home/ai/ is off-limits"

    if profile == "restricted":
        for root in ALLOWED_ROOTS:
            if _is_under_root(real_path, root):
                return True, "ok"
        return False, f"Path is not allowed for profile {profile}"

    if any(_is_under_root(real_path, root) for root in SAUSALITO_DENY_ROOTS):
        return False, "Path is denied: one of the protected /usr/sausalito/ subtrees"

    if _is_under_root(real_path, "/usr/sausalito/"):
        return True, "ok"

    for root in ALLOWED_ROOTS:
        if _is_under_root(real_path, root):
            return True, "ok"

    return False, f"Path is not allowed for profile {profile}"


def _resolve_allowed_path(path: str) -> tuple[bool, str]:
    real_path = os.path.realpath(path)
    ok, reason = _path_allowed_for_profile(real_path)
    if not ok:
        return False, reason
    return True, real_path


def _fmt_mode(mode: int) -> str:
    return stat.filemode(mode)


def _fmt_mtime(ts: float) -> str:
    return time.strftime("%Y-%m-%d %H:%M:%S", time.localtime(ts))


def _owner_name(uid: int) -> str:
    try:
        return pwd.getpwuid(uid).pw_name
    except Exception:
        return str(uid)


def _group_name(gid: int) -> str:
    try:
        return grp.getgrgid(gid).gr_name
    except Exception:
        return str(gid)


def register_tools(executor):
    """Register file inspection tools with the executor."""

    executor.register_tool(
        ToolDefinition(
            name="list_directory",
            description=(
                "List the contents of an approved directory under /home/sites/ or /var/log/. "
                "Use this to inspect web roots, project trees, or directories before reading files."
            ),
            properties={
                "path": {
                    "type": "string",
                    "description": "Directory path under an approved root",
                },
                "recursive": {
                    "type": "boolean",
                    "description": "Whether to recurse into subdirectories",
                    "default": False,
                },
                "max_depth": {
                    "type": "integer",
                    "description": "Maximum recursion depth when recursive is true",
                    "default": 1,
                },
                "max_entries": {
                    "type": "integer",
                    "description": "Maximum number of entries to return",
                    "default": 200,
                },
            },
            required=["path"],
            category="read_only",
        ),
        handle_list_directory,
    )

    executor.register_tool(
        ToolDefinition(
            name="stat_path",
            description=(
                "Inspect metadata for an approved file or directory under /home/sites/ or /var/log/. "
                "Returns mode, owner, group, size, timestamps, and symlink target if present."
            ),
            properties={
                "path": {
                    "type": "string",
                    "description": "File or directory path under an approved root",
                },
            },
            required=["path"],
            category="read_only",
        ),
        handle_stat_path,
    )

    executor.register_tool(
        ToolDefinition(
            name="search_files",
            description=(
                "Search for matching file names and optional file content under an approved root. "
                "Use this for webroot inspection and suspicious file discovery."
            ),
            properties={
                "root": {
                    "type": "string",
                    "description": "Search root under /home/sites/ or /var/log/",
                },
                "name_pattern": {
                    "type": "string",
                    "description": "Glob pattern for file names, e.g. '*.php' or '*.js'",
                },
                "content_pattern": {
                    "type": "string",
                    "description": "Optional regex pattern to search inside text files",
                },
                "max_depth": {
                    "type": "integer",
                    "description": "Maximum directory depth to scan",
                    "default": 5,
                },
                "max_matches": {
                    "type": "integer",
                    "description": "Maximum number of matches to return",
                    "default": 100,
                },
                "max_file_size_mb": {
                    "type": "integer",
                    "description": "Maximum file size to inspect for content matches",
                    "default": 2,
                },
            },
            required=["root"],
            category="read_only",
        ),
        handle_search_files,
    )

    executor.register_tool(
        ToolDefinition(
            name="hash_file",
            description=(
                "Calculate a SHA-256 hash for an approved file under /home/sites/ or /var/log/. "
                "Use this to compare files or check integrity."
            ),
            properties={
                "path": {
                    "type": "string",
                    "description": "File path under an approved root",
                },
            },
            required=["path"],
            category="read_only",
        ),
        handle_hash_file,
    )


async def handle_list_directory(args: dict[str, Any], run_as: str) -> ToolResult:
    path = str(args.get("path", "") or "").strip()
    recursive = bool(args.get("recursive", False))
    max_depth = max(1, min(int(args.get("max_depth", 1) or 1), 10))
    max_entries = max(1, min(int(args.get("max_entries", 200) or 200), 2000))

    ok, resolved = _resolve_allowed_path(path)
    if not ok:
        return ToolResult(success=False, error=resolved)

    if not os.path.isdir(resolved):
        return ToolResult(success=False, error=f"Not a directory: {resolved}")

    rows: list[str] = []
    entry_count = 0
    root_depth = Path(resolved).resolve().parts.__len__()

    def add_entry(entry_path: str) -> bool:
        nonlocal entry_count
        if entry_count >= max_entries:
            return False
        try:
            st = os.lstat(entry_path)
        except FileNotFoundError:
            return True

        mode = _fmt_mode(st.st_mode)
        if stat.S_ISDIR(st.st_mode):
            kind = "dir"
        elif stat.S_ISLNK(st.st_mode):
            kind = "symlink"
        elif stat.S_ISREG(st.st_mode):
            kind = "file"
        else:
            kind = "other"

        rel = os.path.relpath(entry_path, resolved)
        display = "." if rel == "." else rel
        line = f"{mode} {kind:7} {_owner_name(st.st_uid)}:{_group_name(st.st_gid)} {st.st_size:10d} {_fmt_mtime(st.st_mtime)} {display}"
        if stat.S_ISLNK(st.st_mode):
            try:
                target = os.readlink(entry_path)
                line += f" -> {target}"
            except OSError:
                pass

        rows.append(line)
        entry_count += 1
        return entry_count < max_entries

    if recursive:
        for current_root, dirs, files in os.walk(resolved):
            depth = len(Path(current_root).resolve().parts) - root_depth
            if depth >= max_depth:
                dirs[:] = []
            entries = sorted(dirs) + sorted(files)
            for name in entries:
                if not add_entry(os.path.join(current_root, name)):
                    break
            if entry_count >= max_entries:
                break
    else:
        with os.scandir(resolved) as it:
            for entry in sorted(it, key=lambda e: e.name):
                if not add_entry(entry.path):
                    break

    if not rows:
        rows.append("(empty directory)")

    return ToolResult(
        success=True,
        output="\n".join(rows),
        data={"path": resolved, "entries": entry_count, "recursive": recursive},
    )


async def handle_stat_path(args: dict[str, Any], run_as: str) -> ToolResult:
    path = str(args.get("path", "") or "").strip()
    ok, resolved = _resolve_allowed_path(path)
    if not ok:
        return ToolResult(success=False, error=resolved)

    if not os.path.exists(resolved) and not os.path.islink(resolved):
        return ToolResult(success=False, error=f"Path not found: {resolved}")

    try:
        st = os.lstat(resolved)
    except FileNotFoundError:
        return ToolResult(success=False, error=f"Path not found: {resolved}")

    lines = [
        f"path: {resolved}",
        f"type: {'symlink' if stat.S_ISLNK(st.st_mode) else 'directory' if stat.S_ISDIR(st.st_mode) else 'file' if stat.S_ISREG(st.st_mode) else 'other'}",
        f"mode: {_fmt_mode(st.st_mode)} ({oct(st.st_mode & 0o7777)})",
        f"owner: {_owner_name(st.st_uid)} ({st.st_uid})",
        f"group: {_group_name(st.st_gid)} ({st.st_gid})",
        f"size: {st.st_size}",
        f"mtime: {_fmt_mtime(st.st_mtime)}",
        f"ctime: {_fmt_mtime(st.st_ctime)}",
        f"atime: {_fmt_mtime(st.st_atime)}",
    ]

    if stat.S_ISLNK(st.st_mode):
        try:
            lines.append(f"symlink_target: {os.readlink(resolved)}")
        except OSError as e:
            lines.append(f"symlink_target_error: {e}")

    return ToolResult(
        success=True,
        output="\n".join(lines),
        data={"path": resolved},
    )


async def handle_search_files(args: dict[str, Any], run_as: str) -> ToolResult:
    root = str(args.get("root", "") or "").strip()
    name_pattern = str(args.get("name_pattern", "") or "").strip()
    content_pattern = str(args.get("content_pattern", "") or "").strip()
    max_depth = max(1, min(int(args.get("max_depth", 5) or 5), 25))
    max_matches = max(1, min(int(args.get("max_matches", 100) or 100), 1000))
    max_file_size_mb = max(1, min(int(args.get("max_file_size_mb", 2) or 2), 50))

    ok, resolved = _resolve_allowed_path(root)
    if not ok:
        return ToolResult(success=False, error=resolved)
    if not os.path.isdir(resolved):
        return ToolResult(success=False, error=f"Search root is not a directory: {resolved}")

    content_re = None
    if content_pattern:
        import re

        try:
            content_re = re.compile(content_pattern, re.IGNORECASE)
        except re.error as e:
            return ToolResult(success=False, error=f"Invalid content regex: {e}")

    rows: list[str] = []
    root_depth = len(Path(resolved).resolve().parts)
    matched_count = 0

    for current_root, dirs, files in os.walk(resolved):
        depth = len(Path(current_root).resolve().parts) - root_depth
        if depth >= max_depth:
            dirs[:] = []

        for filename in sorted(files):
            candidate = os.path.join(current_root, filename)
            ok, _ = _path_allowed_for_profile(candidate)
            if not ok:
                continue

            matched_by: list[str] = []
            if name_pattern and fnmatch.fnmatch(filename, name_pattern):
                matched_by.append("name")

            if content_re is not None:
                try:
                    if os.path.getsize(candidate) <= max_file_size_mb * 1024 * 1024:
                        with open(candidate, "r", errors="replace") as fh:
                            for lineno, line in enumerate(fh, start=1):
                                if content_re.search(line):
                                    snippet = line.strip()[:200]
                                    rows.append(f"{candidate}:{lineno}: {snippet}")
                                    matched_by.append("content")
                                    matched_count += 1
                                    break
                        if matched_count >= max_matches:
                            break
                except (PermissionError, OSError):
                    continue

            if matched_by and not content_re:
                try:
                    st = os.stat(candidate)
                    rows.append(
                        f"{candidate} | size={st.st_size} | mtime={_fmt_mtime(st.st_mtime)} | matched_by={'+'.join(matched_by)}"
                    )
                    matched_count += 1
                except OSError:
                    continue

            if matched_count >= max_matches:
                break
        if matched_count >= max_matches:
            break

    if not rows:
        rows.append("(no matches)")

    return ToolResult(
        success=True,
        output="\n".join(rows),
        data={"root": resolved, "matches": matched_count},
    )


async def handle_hash_file(args: dict[str, Any], run_as: str) -> ToolResult:
    path = str(args.get("path", "") or "").strip()
    ok, resolved = _resolve_allowed_path(path)
    if not ok:
        return ToolResult(success=False, error=resolved)

    if not os.path.isfile(resolved):
        return ToolResult(success=False, error=f"Not a regular file: {resolved}")

    try:
        hasher = hashlib.sha256()
        with open(resolved, "rb") as fh:
            while True:
                chunk = fh.read(1024 * 1024)
                if not chunk:
                    break
                hasher.update(chunk)
        digest = hasher.hexdigest()
        return ToolResult(
            success=True,
            output=digest,
            data={"path": resolved, "algorithm": "sha256"},
        )
    except PermissionError:
        return ToolResult(success=False, error=f"Permission denied: {resolved}")
    except OSError as e:
        return ToolResult(success=False, error=str(e))
