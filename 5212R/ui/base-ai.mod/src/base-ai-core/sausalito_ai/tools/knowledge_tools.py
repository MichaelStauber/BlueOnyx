"""BlueOnyx knowledge-base search tools."""

from __future__ import annotations

import json
import logging
import re
from pathlib import Path
from typing import Any

from .base import ToolDefinition, ToolResult

logger = logging.getLogger("sausalito_ai.tools.knowledge")

KNOWLEDGE_DIR = Path("/home/ai/knowledgebase")
TRUTH_REGISTRY_PATH = KNOWLEDGE_DIR / "truth_registry.json"
KNOWLEDGE_MD_PATH = KNOWLEDGE_DIR / "blueonyx_knowledge.md"

STOP_WORDS = {
    "the",
    "and",
    "for",
    "with",
    "what",
    "when",
    "where",
    "how",
    "why",
    "does",
    "do",
    "is",
    "are",
    "to",
    "of",
    "a",
    "an",
    "in",
    "on",
    "by",
    "it",
    "this",
    "that",
}


def _load_json(path: Path) -> dict[str, Any]:
    if not path.exists():
        return {}
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except Exception as exc:
        logger.warning("Failed to load knowledge registry %s: %s", path, exc)
        return {}


def _load_text(path: Path) -> str:
    if not path.exists():
        return ""
    try:
        return path.read_text(encoding="utf-8")
    except Exception as exc:
        logger.warning("Failed to load knowledge text %s: %s", path, exc)
        return ""


def _tokenize(query: str) -> list[str]:
    tokens = []
    for token in re.findall(r"[A-Za-z0-9_.@/-]+", (query or "").lower()):
        if len(token) < 3 or token in STOP_WORDS:
            continue
        tokens.append(token)
    return tokens


def _entry_text(entry: dict[str, Any]) -> str:
    parts: list[str] = []
    for key in ("canonical_name", "category", "summary", "safe_answer_template"):
        value = entry.get(key)
        if value:
            parts.append(str(value))
    aliases = entry.get("aliases") or []
    if isinstance(aliases, list):
        parts.extend(str(alias) for alias in aliases if alias)
    return " ".join(parts).lower()


def _score_entry(entry: dict[str, Any], tokens: list[str], query: str) -> int:
    haystack = _entry_text(entry)
    score = 0
    q = (query or "").lower()
    if entry.get("canonical_name") and str(entry["canonical_name"]).lower() in q:
        score += 8
    for alias in entry.get("aliases") or []:
        if isinstance(alias, str) and alias.lower() in q:
            score += 6
    for token in tokens:
        if token in haystack:
            score += 1
    return score


def _search_registry(query: str, limit: int = 5) -> list[dict[str, Any]]:
    registry = _load_json(TRUTH_REGISTRY_PATH)
    entries = registry.get("entries") or []
    tokens = _tokenize(query)
    scored = []
    for entry in entries:
        if not isinstance(entry, dict):
            continue
        score = _score_entry(entry, tokens, query)
        if score > 0:
            scored.append((score, entry))
    scored.sort(key=lambda item: item[0], reverse=True)
    return [entry for _, entry in scored[:limit]]


def _search_markdown(query: str, limit: int = 3) -> list[str]:
    text = _load_text(KNOWLEDGE_MD_PATH)
    if not text.strip():
        return []

    tokens = _tokenize(query)
    if not tokens:
        return []

    sections: list[tuple[str, str]] = []
    current_title = ""
    current_lines: list[str] = []
    for line in text.splitlines():
        if line.startswith("#"):
            if current_title or current_lines:
                sections.append((current_title, "\n".join(current_lines).strip()))
            current_title = line.strip("# ").strip()
            current_lines = []
        else:
            current_lines.append(line.rstrip())
    if current_title or current_lines:
        sections.append((current_title, "\n".join(current_lines).strip()))

    scored: list[tuple[int, str]] = []
    for title, body in sections:
        haystack = f"{title}\n{body}".lower()
        score = sum(1 for token in tokens if token in haystack)
        if score > 0:
            excerpt_lines = [line for line in body.splitlines() if line.strip()][:8]
            excerpt = "\n".join(excerpt_lines).strip()
            scored.append((score, f"{title}\n{excerpt}".strip()))

    scored.sort(key=lambda item: item[0], reverse=True)
    return [item[1] for item in scored[:limit]]


def build_knowledge_brief(max_entries: int = 8, max_chars: int = 1800) -> str:
    registry = _load_json(TRUTH_REGISTRY_PATH)
    lines: list[str] = []

    for rule in registry.get("default_rules") or []:
        if isinstance(rule, str) and rule.strip():
            lines.append(f"- {rule.strip()}")

    entries = registry.get("entries") or []
    count = 0
    for entry in entries:
        if count >= max_entries:
            break
        if not isinstance(entry, dict):
            continue
        name = str(entry.get("canonical_name") or entry.get("id") or "").strip()
        summary = str(entry.get("summary") or entry.get("safe_answer_template") or "").strip()
        if not name or not summary:
            continue
        lines.append(f"- {name}: {summary}")
        count += 1

    if not lines:
        return ""

    lines.insert(0, "BlueOnyx canonical facts:")
    brief = "\n".join(lines).strip()
    if len(brief) <= max_chars:
        return brief
    return brief[:max_chars].rstrip() + "\n...[truncated]..."


def _format_registry_hit(entry: dict[str, Any]) -> list[str]:
    lines: list[str] = []
    name = str(entry.get("canonical_name") or entry.get("id") or "unknown").strip()
    summary = str(entry.get("summary") or entry.get("safe_answer_template") or "").strip()
    category = str(entry.get("category") or "misc").strip()
    aliases = entry.get("aliases") or []
    alias_text = ", ".join(alias for alias in aliases if isinstance(alias, str) and alias.strip())
    lines.append(f"- {name} [{category}]")
    if summary:
        lines.append(f"  - {summary}")
    if alias_text:
        lines.append(f"  - Aliases: {alias_text}")
    tools = entry.get("authoritative_tools") or []
    if isinstance(tools, list) and tools:
        tool_text = ", ".join(tool for tool in tools if isinstance(tool, str) and tool.strip())
        if tool_text:
            lines.append(f"  - Authoritative tools: {tool_text}")
    return lines


def search_blueonyx_knowledge(query: str) -> str:
    query = (query or "").strip()
    if not query:
        return "No query provided."

    registry_hits = _search_registry(query)
    md_hits = _search_markdown(query)

    if not registry_hits and not md_hits:
        return "(no matches found)"

    lines: list[str] = [
        "BlueOnyx Knowledge:",
        f"Query: {query}",
    ]

    if registry_hits:
        lines.append("")
        lines.append("Registry hits:")
        for entry in registry_hits:
            lines.extend(_format_registry_hit(entry))

    if md_hits:
        lines.append("")
        lines.append("Knowledge file hits:")
        for hit in md_hits:
            lines.append(f"- {hit}")

    return "\n".join(lines).strip()


def register_tools(executor):
    executor.register_tool(
        ToolDefinition(
            name="search_blueonyx_knowledge",
            description=(
                "Search the local BlueOnyx truth registry and glossary for canonical facts, "
                "aliases, and short support guidance. Use this for BlueOnyx-specific concepts "
                "when you need an anchored answer instead of guessing."
            ),
            properties={
                "query": {
                    "type": "string",
                    "description": "Search text or question to look up in the local BlueOnyx knowledge base",
                }
            },
            required=["query"],
            category="read_only",
        ),
        _handle_search_blueonyx_knowledge,
    )


async def _handle_search_blueonyx_knowledge(args: dict, run_as: str) -> ToolResult:
    query = str(args.get("query", "") or "").strip()
    output = search_blueonyx_knowledge(query)
    return ToolResult(success=True, output=output)
