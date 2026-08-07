"""
model_caps.py -- Model capability classification for BlueOnyx AI.

Copyright (c) 2008-2026 Michael Stauber, SOLARSPEED.NET
Copyright (c) 2008-2026 Team BlueOnyx, BLUEONYX.IT
All Rights Reserved.

Provides a small local cache and heuristic classifier that maps a model
selection to a capability profile:
    - restricted
    - guided
    - investigative
    - freeform

The first version is intentionally conservative. Unknown models are
classified using provider/model-name heuristics and persisted to a runtime
cache so future runs keep the same policy unless the model string changes.
"""

from __future__ import annotations

import json
import logging
import re
from dataclasses import dataclass, field
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

logger = logging.getLogger("sausalito_ai.model_caps")

KNOWLEDGEBASE_DIR = Path("/home/ai/knowledgebase")
SEED_PATH = KNOWLEDGEBASE_DIR / "model_caps.json"
RUNTIME_CACHE_PATH = Path("/home/ai/model_caps.runtime.json")

PROFILE_ORDER = ("restricted", "guided", "investigative", "freeform")

# Confidence threshold below which a heuristic classification is considered
# unreliable and a runtime probe should be used to refine the profile.
PROBE_CONFIDENCE_THRESHOLD = 0.70

# Profile order ranking for comparison (higher = more capable)
PROFILE_RANK = {"restricted": 0, "guided": 1, "investigative": 2, "freeform": 3}


def _utc_now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def _normalize_provider(provider: str) -> str:
    return str(provider or "unknown").strip().lower() or "unknown"


def _normalize_model(model: str) -> str:
    value = str(model or "").strip()
    if not value:
        return "unknown"
    return value


def _cap_key(provider: str, model: str) -> str:
    return f"{_normalize_provider(provider)}/{_normalize_model(model).lower()}"


def _score_profile(profile: str) -> dict[str, int]:
    if profile == "restricted":
        return {
            "reasoning_score": 1,
            "tool_score": 1,
            "format_score": 2,
            "hallucination_score": 1,
            "investigation_score": 1,
        }
    if profile == "guided":
        return {
            "reasoning_score": 3,
            "tool_score": 3,
            "format_score": 3,
            "hallucination_score": 3,
            "investigation_score": 3,
        }
    if profile == "investigative":
        return {
            "reasoning_score": 4,
            "tool_score": 4,
            "format_score": 4,
            "hallucination_score": 4,
            "investigation_score": 4,
        }
    return {
        "reasoning_score": 5,
        "tool_score": 5,
        "format_score": 5,
        "hallucination_score": 5,
        "investigation_score": 5,
    }


@dataclass
class ModelCapabilityRecord:
    provider: str
    model: str
    profile: str
    confidence: float
    source: str
    notes: list[str] = field(default_factory=list)
    reasoning_score: int = 3
    tool_score: int = 3
    format_score: int = 3
    hallucination_score: int = 3
    investigation_score: int = 3
    last_tested: str = field(default_factory=_utc_now)
    test_count: int = 1
    instruction_score: float | None = None
    format_score: float | None = None
    tool_calling_score: float | None = None

    @property
    def key(self) -> str:
        return _cap_key(self.provider, self.model)

    def to_dict(self) -> dict[str, Any]:
        return {
            "provider": self.provider,
            "model": self.model,
            "profile": self.profile,
            "confidence": self.confidence,
            "source": self.source,
            "notes": list(self.notes),
            "reasoning_score": self.reasoning_score,
            "tool_score": self.tool_score,
            "format_score": self.format_score,
            "hallucination_score": self.hallucination_score,
            "investigation_score": self.investigation_score,
            "last_tested": self.last_tested,
            "test_count": self.test_count,
            "instruction_score": self.instruction_score,
            "format_score_probe": self.format_score if self.format_score is not None else None,
            "tool_calling_score": self.tool_calling_score,
        }


class ModelCapabilityStore:
    """Persistent capability cache with conservative heuristics."""

    def __init__(self, seed_path: Path = SEED_PATH, runtime_path: Path = RUNTIME_CACHE_PATH) -> None:
        self.seed_path = seed_path
        self.runtime_path = runtime_path
        self._data = self._load()

    def _empty_store(self) -> dict[str, Any]:
        return {
            "schema_version": "1.0",
            "last_global_retest": None,
            "models": {},
        }

    def _load_json(self, path: Path) -> dict[str, Any]:
        if not path.exists():
            return {}
        try:
            with path.open("r", encoding="utf-8") as handle:
                data = json.load(handle)
            return data if isinstance(data, dict) else {}
        except Exception as exc:
            logger.warning("Failed to load model caps from %s: %s", path, exc)
            return {}

    def _load(self) -> dict[str, Any]:
        data = self._empty_store()
        seed = self._load_json(self.seed_path)
        runtime = self._load_json(self.runtime_path)

        for source in (seed, runtime):
            if not source:
                continue
            models = source.get("models", {})
            if isinstance(models, dict):
                data["models"].update(models)
            if source.get("schema_version"):
                data["schema_version"] = str(source.get("schema_version"))
            if source.get("last_global_retest"):
                data["last_global_retest"] = source.get("last_global_retest")

        return data

    def _save_runtime(self) -> None:
        try:
            self.runtime_path.parent.mkdir(parents=True, exist_ok=True)
            with self.runtime_path.open("w", encoding="utf-8") as handle:
                json.dump(self._data, handle, indent=2, sort_keys=True, ensure_ascii=False)
                handle.write("\n")
        except Exception as exc:
            logger.warning("Failed to save model caps runtime cache %s: %s", self.runtime_path, exc)

    def get(self, provider: str, model: str) -> ModelCapabilityRecord:
        provider = _normalize_provider(provider)
        model = _normalize_model(model)
        key = _cap_key(provider, model)

        cached = self._data.get("models", {}).get(key)
        if isinstance(cached, dict) and cached.get("profile"):
            record = self._from_cached(provider, model, cached)
            # If cached record has probe scores, trust those over heuristic
            if cached.get("source") == "probe" and cached.get("probe_overall_score") is not None:
                return record
            # If cached with low confidence and no probe, mark for potential probe
            if record.confidence < PROBE_CONFIDENCE_THRESHOLD:
                record.notes = list(record.notes) + ["Low heuristic confidence — probe recommended"]
            return record

        record = self._classify(provider, model)
        self._data.setdefault("models", {})[key] = record.to_dict()
        self._save_runtime()
        return record

    def update_from_probe(
        self,
        provider: str,
        model: str,
        probe_result: dict[str, Any],
    ) -> ModelCapabilityRecord:
        """Update the capability record based on probe results.

        Called after a successful capability probe. Updates the profile,
        scores, and confidence based on actual model behavior.
        """
        provider = _normalize_provider(provider)
        model = _normalize_model(model)
        key = _cap_key(provider, model)

        # Get existing record (or classify fresh)
        existing = self.get(provider, model)

        probe_profile = str(probe_result.get("suggested_profile", "guided"))
        if probe_profile not in PROFILE_RANK:
            probe_profile = "guided"

        probe_score = float(probe_result.get("overall_score", 0.0))
        probe_instruction = float(probe_result.get("instruction_score", 0.0))
        probe_format = float(probe_result.get("format_score", 0.0))
        probe_tool = float(probe_result.get("tool_calling_score", 0.0))

        # Determine final profile: probe result takes precedence when
        # the probe suggests a HIGHER capability than the heuristic,
        # or when heuristic confidence was low.
        # We never UPGRADE beyond what the heuristic allows for local models
        # (local models are always capped at "restricted").
        if provider == "local":
            final_profile = "restricted"
        elif probe_score >= 0.75 and PROFILE_RANK.get(probe_profile, 1) >= PROFILE_RANK.get(existing.profile, 1):
            # Probe confirms equal or higher capability → trust the probe
            final_profile = probe_profile
        elif existing.confidence < PROBE_CONFIDENCE_THRESHOLD:
            # Low heuristic confidence → trust the probe
            final_profile = probe_profile
        else:
            # High heuristic confidence → keep heuristic, but log probe data
            final_profile = existing.profile

        # Recalculate scores from profile
        new_scores = _score_profile(final_profile)
        # But also store actual probe scores for future reference
        new_scores["instruction_score"] = probe_instruction
        new_scores["format_score"] = probe_format
        new_scores["tool_calling_score"] = probe_tool

        # Confidence from probe is higher (we measured it)
        probe_confidence = min(0.95, 0.50 + probe_score * 0.50)

        notes = list(existing.notes)
        notes.append(f"Probe: overall={probe_score:.2f} profile={probe_profile}")
        notes = list(dict.fromkeys(notes))  # deduplicate while preserving order

        record = ModelCapabilityRecord(
            provider=provider,
            model=existing.model,
            profile=final_profile,
            confidence=probe_confidence,
            source="probe",
            notes=notes,
            reasoning_score=new_scores.get("reasoning_score", existing.reasoning_score),
            tool_score=new_scores.get("tool_score", existing.tool_score),
            format_score=new_scores.get("format_score", existing.format_score),
            hallucination_score=new_scores.get("hallucination_score", existing.hallucination_score),
            investigation_score=new_scores.get("investigation_score", existing.investigation_score),
            last_tested=_utc_now(),
            test_count=existing.test_count + 1,
            instruction_score=probe_instruction,
            tool_calling_score=probe_tool,
        )

        self._data.setdefault("models", {})[key] = record.to_dict()
        self._save_runtime()

        logger.info(
            "Probe updated %s/%s: heuristic=%s probe=%s final=%s confidence=%.2f",
            provider, existing.model, existing.profile, probe_profile,
            final_profile, probe_confidence,
        )

        return record

    def _from_cached(self, provider: str, model: str, cached: dict[str, Any]) -> ModelCapabilityRecord:
        scores = {
            "reasoning_score": int(cached.get("reasoning_score", 3) or 3),
            "tool_score": int(cached.get("tool_score", 3) or 3),
            "format_score": int(cached.get("format_score", 3) or 3),
            "hallucination_score": int(cached.get("hallucination_score", 3) or 3),
            "investigation_score": int(cached.get("investigation_score", 3) or 3),
        }
        return ModelCapabilityRecord(
            provider=provider,
            model=model,
            profile=str(cached.get("profile", "guided")),
            confidence=float(cached.get("confidence", 0.5) or 0.5),
            source=str(cached.get("source", "cache")),
            notes=list(cached.get("notes", [])) if isinstance(cached.get("notes", []), list) else [],
            last_tested=str(cached.get("last_tested", _utc_now())),
            test_count=int(cached.get("test_count", 1) or 1),
            **scores,
        )

    def _classify(self, provider: str, model: str) -> ModelCapabilityRecord:
        raw = model.strip()
        norm = raw.lower()
        provider_norm = provider.lower()
        tokens = {
            "smol": "smol" in norm,
            "mini": "mini" in norm,
            "tiny": "tiny" in norm,
            "small": "small" in norm,
            "360m": "360m" in norm,
            "1b": bool(re.search(r"\b1[bm]\b", norm)),
            "2b": bool(re.search(r"\b2[bm]\b", norm)),
            "gpt4o": "gpt-4o" in norm,
            "gpt5": "gpt-5" in norm,
            "gpt41": "gpt-4.1" in norm,
            "o3": bool(re.search(r"\bo3\b", norm)),
            "o4": bool(re.search(r"\bo4\b", norm)),
            "sonnet": "sonnet" in norm,
            "opus": "opus" in norm,
            "claude4": "claude-4" in norm,
            "claude37": "claude-3-7" in norm,
            "kimi": "kimi" in norm,
            "qwen": "qwen" in norm,
            "gemini25": "gemini-2.5" in norm or "gemini 2.5" in norm,
            "gemini25pro": "gemini-2.5-pro" in norm or "gemini 2.5 pro" in norm,
        }

        if provider_norm == "local":
            profile = "restricted"
            confidence = 0.97
            notes = ["Local models default to conservative tool guidance."]
        elif tokens["smol"] or tokens["360m"] or tokens["1b"] or tokens["2b"]:
            profile = "restricted"
            confidence = 0.93
            notes = ["Small model family detected from model name."]
        elif tokens["mini"] or tokens["tiny"] or tokens["small"]:
            profile = "guided"
            confidence = 0.82
            notes = ["Mini/small-style model name detected."]
        elif tokens["gpt5"] or tokens["o3"] or tokens["o4"] or tokens["claude4"] or tokens["gemini25pro"]:
            profile = "freeform"
            confidence = 0.80
            notes = ["Top-tier model family detected from name."]
        elif tokens["gpt4o"] or tokens["gpt41"] or tokens["sonnet"] or tokens["opus"] or tokens["claude4"] or tokens["claude37"] or tokens["kimi"] or tokens["qwen"] or tokens["gemini25"]:
            profile = "investigative"
            confidence = 0.84
            notes = ["Strong reasoning model family detected from name."]
        elif provider_norm in {"openai", "anthropic"}:
            profile = "investigative"
            confidence = 0.75
            notes = ["Provider defaults to a stronger reasoning profile."]
        elif provider_norm in {"openrouter", "ollama", "custom"}:
            profile = "guided"
            confidence = 0.68
            notes = ["Unknown or mixed provider defaults to guided behavior."]
        else:
            profile = "guided"
            confidence = 0.60
            notes = ["Unknown model defaults to conservative guided behavior."]

        if tokens["smol"] or tokens["360m"]:
            profile = "restricted"
        elif profile == "guided" and tokens["kimi"]:
            profile = "investigative"

        scores = _score_profile(profile)
        return ModelCapabilityRecord(
            provider=provider_norm,
            model=raw,
            profile=profile,
            confidence=confidence,
            source="heuristic",
            notes=notes,
            **scores,
        )


_STORE: ModelCapabilityStore | None = None


def get_model_capabilities(provider: str, model: str) -> ModelCapabilityRecord:
    global _STORE
    if _STORE is None:
        _STORE = ModelCapabilityStore()
    return _STORE.get(provider, model)
