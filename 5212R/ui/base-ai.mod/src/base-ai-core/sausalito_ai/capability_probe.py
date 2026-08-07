#!/usr/bin/env python3
"""
capability_probe.py -- Runtime capability probe for unknown LLM models.

Tests a model's instruction-following, JSON formatting, and tool-calling
ability to refine the profile assignment beyond heuristics.

The probe is lightweight (3 short requests) and the results are cached
in the ModelCapabilityStore for future runs.
"""

from __future__ import annotations

import json
import logging
import time
from dataclasses import dataclass
from typing import Any

logger = logging.getLogger("sausalito_ai.capability_probe")

# ---------------------------------------------------------------------------
# Probe definitions — minimal prompts that test core capabilities
# ---------------------------------------------------------------------------

PROBE_INSTRUCTION_FOLLOW = """Respond with exactly this text and nothing else: "PROBE_OK" """

PROBE_JSON_FORMAT = """Return a JSON object with exactly two keys:
- "status": "ok"
- "count": 3
No other text, no markdown, just the JSON object."""

PROBE_TOOL_CALL = """You have access to a tool called "system_uname" that returns system information.
Call this tool now. Do not include any other text — just the tool call."""

# ---------------------------------------------------------------------------
# Scoring thresholds
# ---------------------------------------------------------------------------

# Minimum scores to qualify for each profile.
# If a model scores below RESTRICTED_THRESHOLD, it stays restricted anyway.
INVESTIGATIVE_THRESHOLD = 0.75
GUIDED_THRESHOLD = 0.50

# Weights for each probe dimension
WEIGHTS = {
    "instruction": 0.30,
    "format": 0.35,
    "tool_calling": 0.35,
}


def _score_instruction_follow(response: str) -> float:
    """Score instruction-following ability (0.0 - 1.0)."""
    text = (response or "").strip()
    if not text:
        return 0.0
    # Exact match
    if text == "PROBE_OK":
        return 1.0
    # Near match (extra whitespace, quotes, punctuation)
    normalized = text.lower().strip('"\'`.,:;')
    if normalized == "probe_ok":
        return 0.85
    # Contains the key phrase
    if "probe_ok" in text.lower():
        return 0.5
    # Any coherent response at all
    if len(text) > 3:
        return 0.2
    return 0.0


def _score_json_format(response: str) -> float:
    """Score JSON formatting ability (0.0 - 1.0)."""
    text = (response or "").strip()
    if not text:
        return 0.0
    # Strip markdown code fences if present
    if text.startswith("```"):
        lines = text.split("\n")
        text = "\n".join(lines[1:-1]).strip()
    try:
        obj = json.loads(text)
        if not isinstance(obj, dict):
            return 0.3
        score = 0.0
        if obj.get("status") == "ok":
            score += 0.5
        if obj.get("count") == 3:
            score += 0.5
        # Partial credit for valid JSON with wrong values
        if score == 0.0 and len(obj) > 0:
            score = 0.3
        return min(score, 1.0)
    except (json.JSONDecodeError, ValueError):
        # Try to extract JSON from surrounding text
        import re
        json_match = re.search(r'\{[^}]+\}', text)
        if json_match:
            try:
                obj = json.loads(json_match.group())
                if isinstance(obj, dict):
                    return 0.4  # Found JSON but had to extract it
            except (json.JSONDecodeError, ValueError):
                pass
        return 0.0


def _score_tool_calling(tool_calls: list[dict] | None, response_text: str) -> float:
    """Score tool-calling ability (0.0 - 1.0)."""
    if not tool_calls:
        # No tool call at all — check if the response mentions system_uname
        text = (response_text or "").lower()
        if "system_uname" in text or "uname" in text:
            return 0.2  # Mentioned the tool but didn't call it
        return 0.0
    # At least one tool call was made
    score = 0.3
    for tc in tool_calls:
        name = (tc.get("name") or "").lower()
        if "uname" in name:
            score += 0.5
            # Check if arguments are reasonable
            args = tc.get("arguments") or {}
            if isinstance(args, dict) and len(args) == 0:
                score += 0.2  # Correct: no args needed for system_uname
            break
    return min(score, 1.0)


@dataclass
class ProbeResult:
    """Result of a capability probe."""
    instruction_score: float
    format_score: float
    tool_calling_score: float
    overall_score: float
    suggested_profile: str
    elapsed_seconds: float
    notes: list[str]

    def to_dict(self) -> dict[str, Any]:
        return {
            "instruction_score": round(self.instruction_score, 2),
            "format_score": round(self.format_score, 2),
            "tool_calling_score": round(self.tool_calling_score, 2),
            "overall_score": round(self.overall_score, 2),
            "suggested_profile": self.suggested_profile,
            "elapsed_seconds": round(self.elapsed_seconds, 2),
            "notes": self.notes,
        }


async def run_capability_probe(provider: Any, timeout: float = 30.0) -> ProbeResult:
    """Run a lightweight capability probe against the model.

    Tests three capabilities:
    1. Instruction following (can it follow a simple instruction?)
    2. JSON formatting (can it produce valid JSON?)
    3. Tool calling (can it call a tool correctly?)

    Returns a ProbeResult with scores and a suggested profile.
    """
    start = time.monotonic()
    instruction_score = 0.0
    format_score = 0.0
    tool_calling_score = 0.0
    notes: list[str] = []

    # --- Probe 1: Instruction following ---
    try:
        messages = [{"role": "user", "content": PROBE_INSTRUCTION_FOLLOW}]
        response_text = ""
        async for event in provider.chat(messages=messages, tools=None, stream=True):
            if event.get("type") == "delta":
                response_text += event.get("content", "")
            elif event.get("type") == "error":
                notes.append(f"Instruction probe error: {event.get('message', 'unknown')}")
                break
        instruction_score = _score_instruction_follow(response_text)
        logger.info("Probe instruction-following score: %.2f (response: %s)",
                     instruction_score, repr(response_text[:100]))
    except Exception as exc:
        logger.warning("Instruction probe failed: %s", exc)
        notes.append(f"Instruction probe failed: {exc}")

    # --- Probe 2: JSON formatting ---
    try:
        messages = [{"role": "user", "content": PROBE_JSON_FORMAT}]
        response_text = ""
        async for event in provider.chat(messages=messages, tools=None, stream=True):
            if event.get("type") == "delta":
                response_text += event.get("content", "")
            elif event.get("type") == "error":
                notes.append(f"Format probe error: {event.get('message', 'unknown')}")
                break
        format_score = _score_json_format(response_text)
        logger.info("Probe JSON format score: %.2f (response: %s)",
                     format_score, repr(response_text[:100]))
    except Exception as exc:
        logger.warning("Format probe failed: %s", exc)
        notes.append(f"Format probe failed: {exc}")

    # --- Probe 3: Tool calling ---
    try:
        from ..tools.base import ToolExecutor
        tool_executor = ToolExecutor()
        # Get just the system_uname tool definition
        uname_tool = tool_executor.get_tool("system_uname")
        tools = [uname_tool.to_dict()] if uname_tool else None
        messages = [{"role": "user", "content": PROBE_TOOL_CALL}]
        response_text = ""
        tool_calls: list[dict] = []
        async for event in provider.chat(messages=messages, tools=tools, stream=True):
            if event.get("type") == "delta":
                response_text += event.get("content", "")
            elif event.get("type") == "tool_call":
                tool_calls.append(event)
            elif event.get("type") == "error":
                notes.append(f"Tool-call probe error: {event.get('message', 'unknown')}")
                break
        tool_calling_score = _score_tool_calling(tool_calls, response_text)
        logger.info("Probe tool-calling score: %.2f (tool_calls: %d)",
                     tool_calling_score, len(tool_calls))
    except Exception as exc:
        logger.warning("Tool-calling probe failed: %s", exc)
        notes.append(f"Tool-calling probe failed: {exc}")

    elapsed = time.monotonic() - start

    # --- Calculate overall score and suggest profile ---
    overall_score = (
        WEIGHTS["instruction"] * instruction_score
        + WEIGHTS["format"] * format_score
        + WEIGHTS["tool_calling"] * tool_calling_score
    )
    overall_score = round(overall_score, 2)

    if overall_score >= INVESTIGATIVE_THRESHOLD:
        suggested_profile = "investigative"
    elif overall_score >= GUIDED_THRESHOLD:
        suggested_profile = "guided"
    else:
        suggested_profile = "restricted"

    notes.append(f"Overall score: {overall_score:.2f} → {suggested_profile}")

    return ProbeResult(
        instruction_score=instruction_score,
        format_score=format_score,
        tool_calling_score=tool_calling_score,
        overall_score=overall_score,
        suggested_profile=suggested_profile,
        elapsed_seconds=elapsed,
        notes=notes,
    )
