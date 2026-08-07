"""
external_provider.py -- LiteLLM wrapper for BlueOnyx AI service.

Copyright (c) 2008-2026 Michael Stauber, SOLARSPEED.NET
Copyright (c) 2008-2026 Team BlueOnyx, BLUEONYX.IT
All Rights Reserved.

Provides an async LLM provider with streaming support,
error handling, retry logic, and configurable timeouts.
"""

from __future__ import annotations

import asyncio
import json
import logging
import os
from typing import Any, AsyncGenerator, Optional

# LiteLLM rewrites TIKTOKEN_CACHE_DIR on import unless CUSTOM_TIKTOKEN_CACHE_DIR
# is set first. Keep its tokenizer cache under the writable AI runtime tree.
os.environ.setdefault("CUSTOM_TIKTOKEN_CACHE_DIR", os.environ.get("TIKTOKEN_CACHE_DIR", "/home/ai/.cache/tiktoken"))

import litellm

logger = logging.getLogger("sausalito_ai.providers.external")


class ExternalProvider:
    """Async LLM provider wrapping litellm.acompletion with streaming support.

    Configuration dictionary keys:
        provider (str): Backend provider name (e.g. "openai", "anthropic", "azure")
        openai_api_key (str): OpenAI API key
        openrouter_api_key (str): OpenRouter API key
        ollama_api_key (str): Ollama API key
        custom_api_key (str): Custom endpoint API key
        model (str): Model name (e.g. "gpt-4", "claude-3-opus-20240229")
        endpoint (str, optional): Custom API endpoint URL
        custom_endpoint (str, optional): Alias for endpoint used by the GUI
        max_tokens (int, optional): Maximum output tokens. Default 4096.
        temperature (float, optional): Sampling temperature. Default 0.7.
        timeout (int, optional): Request timeout in seconds. Default 30.
        max_retries (int, optional): Number of retries on 5xx. Default 2.
    """

    def __init__(self, config: dict) -> None:
        self.name: str = config.get("provider", "openai")
        self.api_key: str = self._resolve_api_key(config)
        model_value = config.get("model") or config.get("default_model") or "gpt-4"
        self.model: str = str(model_value).strip()
        self.endpoint: Optional[str] = config.get("custom_endpoint") or config.get("endpoint")
        self.max_tokens: int = config.get("max_tokens", 4096)
        self.temperature: float = config.get("temperature", 0.7)
        self.timeout: int = config.get("timeout", 30)
        self.max_retries: int = config.get("max_retries", 2)

        # Configure litellm
        litellm.drop_params = True
        litellm.set_verbose = False

        # For local provider, use llama.cpp server endpoint
        if self.name == "local":
            # Use the dedicated BlueOnyx llama service on an uncommon port.
            self.endpoint = self.endpoint or "http://127.0.0.1:8081"
            # Set model to openai/<name> so litellm uses OpenAI-compatible provider
            # The actual model name is ignored by llama-server (loaded at startup)
            self.model = "openai/llama-model"
            # Set dummy API key (llama-server doesn't need it, but litellm checks)
            self.api_key = self.api_key or "not-needed"
            logger.info(
                "Local provider: using llama.cpp server at %s",
                self.endpoint,
            )
        elif self.name == "openrouter":
            # OpenRouter is OpenAI-compatible but needs the OpenRouter base URL.
            self.endpoint = self.endpoint or "https://openrouter.ai/api/v1"
            self.model = f"openrouter/{self.model}"
            logger.info(
                "OpenRouter provider: using endpoint=%s model=%s",
                self.endpoint,
                self.model,
            )
        elif self.name == "ollama":
            # Ollama provider means Ollama Cloud.
            # Local/self-hosted Ollama must be configured via Custom Provider.
            if self.endpoint and self.endpoint != "https://ollama.com":
                logger.info(
                    "Ignoring custom Ollama endpoint %s; using Ollama Cloud endpoint instead",
                    self.endpoint,
                )
            # Ollama Cloud exposes an OpenAI-compatible /v1 API surface.
            self.endpoint = "https://ollama.com/v1"
            self.model = f"openai/{self.model}"
            logger.info(
                "Ollama provider: using OpenAI-compatible endpoint=%s model=%s",
                self.endpoint,
                self.model,
            )
        elif self.name == "custom":
            # Custom Provider may point at a local Ollama instance.
            # If the endpoint looks Ollama-like, tell litellm explicitly.
            endpoint_hint = (self.endpoint or "").lower()
            if "11434" in endpoint_hint or "/api/tags" in endpoint_hint or "ollama" in endpoint_hint:
                self.model = f"openai/{self.model}"
                logger.info(
                    "Custom provider detected Ollama-style endpoint: using OpenAI-compatible model=%s endpoint=%s",
                    self.model,
                    self.endpoint or "default",
                )
        if self.api_key:
            litellm.api_key = self.api_key
            if self.name == "ollama":
                os.environ["OLLAMA_API_KEY"] = self.api_key

        logger.info(
            "ExternalProvider initialized: provider=%s model=%s endpoint=%s",
            self.name,
            self.model,
            self.endpoint or "default",
        )

    def _resolve_api_key(self, config: dict) -> str:
        provider_map = {
            "openai": "openai_api_key",
            "openrouter": "openrouter_api_key",
            "ollama": "ollama_api_key",
            "custom": "custom_api_key",
        }

        provider_key = provider_map.get(str(config.get("provider", "openai") or "openai").strip().lower(), "")
        if not provider_key:
            return ""

        return str(config.get(provider_key, "") or "").strip()

    async def chat(
        self,
        messages: list[dict],
        tools: Optional[list[dict]] = None,
        stream: bool = True,
    ) -> AsyncGenerator[dict[str, Any], None]:
        """Send a chat completion request and yield events.

        For streaming responses, yields:
            {"type": "delta", "content": "..."}
            {"type": "tool_call", "id": "...", "name": "...", "arguments": {...}}

        For non-streaming responses, yields the single final response event.
        On error yields:
            {"type": "error", "message": "..."}
        """
        kwargs: dict[str, Any] = {
            "model": self.model,
            "messages": messages,
            "max_tokens": self.max_tokens,
            "temperature": self.temperature,
            "stream": stream,
        }

        if self.endpoint:
            kwargs["api_base"] = self.endpoint

        if self.api_key:
            kwargs["api_key"] = self.api_key

        if tools:
            kwargs["tools"] = tools
            kwargs["tool_choice"] = "auto"

        last_exception: Optional[Exception] = None

        for attempt in range(1 + self.max_retries):
            try:
                response = await asyncio.wait_for(
                    litellm.acompletion(**kwargs),
                    timeout=self.timeout,
                )

                if stream:
                    tool_call_acc: dict[str, Any] = {}
                    async for chunk in response:
                        delta = chunk.choices[0].delta if chunk.choices else None
                        if delta is None:
                            continue

                        # Text delta
                        if delta.content:
                            yield {"type": "delta", "content": delta.content}

                        # Tool call accumulation (streaming tool_calls)
                        if delta.tool_calls:
                            for tc in delta.tool_calls:
                                idx = tc.index
                                if idx not in tool_call_acc:
                                    tool_call_acc[idx] = {
                                        "id": tc.id or "",
                                        "name": "",
                                        "arguments": "",
                                    }
                                acc = tool_call_acc[idx]
                                if tc.id:
                                    acc["id"] = tc.id
                                if tc.function and tc.function.name:
                                    acc["name"] = tc.function.name
                                if tc.function and tc.function.arguments:
                                    acc["arguments"] += tc.function.arguments

                        # Finish reason indicates tool calls are complete
                        finish_reason = chunk.choices[0].finish_reason if chunk.choices else None
                        if finish_reason == "tool_calls":
                            # Yield complete tool calls
                            for idx in sorted(tool_call_acc.keys()):
                                acc = tool_call_acc[idx]
                                try:
                                    args_dict = json.loads(acc["arguments"]) if acc["arguments"] else {}
                                except json.JSONDecodeError:
                                    args_dict = {"_raw": acc["arguments"]}
                                yield {
                                    "type": "tool_call",
                                    "id": acc["id"],
                                    "name": acc["name"],
                                    "arguments": args_dict,
                                }
                            tool_call_acc = {}
                        elif finish_reason == "stop":
                            # Flush any remaining tool calls
                            for idx in sorted(tool_call_acc.keys()):
                                acc = tool_call_acc[idx]
                                try:
                                    args_dict = json.loads(acc["arguments"]) if acc["arguments"] else {}
                                except json.JSONDecodeError:
                                    args_dict = {"_raw": acc["arguments"]}
                                yield {
                                    "type": "tool_call",
                                    "id": acc["id"],
                                    "name": acc["name"],
                                    "arguments": args_dict,
                                }
                            tool_call_acc = {}

                    # After streaming completes, check for any remaining accumulated tool calls
                    if tool_call_acc:
                        for idx in sorted(tool_call_acc.keys()):
                            acc = tool_call_acc[idx]
                            try:
                                args_dict = json.loads(acc["arguments"]) if acc["arguments"] else {}
                            except json.JSONDecodeError:
                                args_dict = {"_raw": acc["arguments"]}
                            yield {
                                "type": "tool_call",
                                "id": acc["id"],
                                "name": acc["name"],
                                "arguments": args_dict,
                            }
                else:
                    # Non-streaming
                    choice = response.choices[0]
                    if choice.finish_reason == "tool_calls" and choice.message.tool_calls:
                        for tc in choice.message.tool_calls:
                            try:
                                args_dict = json.loads(tc.function.arguments) if tc.function.arguments else {}
                            except json.JSONDecodeError:
                                args_dict = {"_raw": tc.function.arguments}
                            yield {
                                "type": "tool_call",
                                "id": tc.id,
                                "name": tc.function.name,
                                "arguments": args_dict,
                            }
                    elif choice.message.content:
                        yield {"type": "delta", "content": choice.message.content}

                # Success -- exit retry loop
                return

            except asyncio.TimeoutError as e:
                last_exception = e
                logger.warning(
                    "Provider timeout (attempt %d/%d): %s",
                    attempt + 1,
                    1 + self.max_retries,
                    self.model,
                )
                if attempt < self.max_retries:
                    await asyncio.sleep(1 * (attempt + 1))
                continue

            except Exception as e:
                last_exception = e
                error_str = str(e).lower()

                # Only retry on 5xx or rate-limit errors
                if any(code in error_str for code in ["500", "502", "503", "504", "429", "rate_limit"]):
                    logger.warning(
                        "Provider transient error (attempt %d/%d): %s",
                        attempt + 1,
                        1 + self.max_retries,
                        e,
                    )
                    if attempt < self.max_retries:
                        await asyncio.sleep(2 * (attempt + 1))
                    continue

                # Non-retryable error
                logger.error("Provider non-retryable error: %s", e)
                yield {"type": "error", "message": str(e)}
                return

        # All retries exhausted
        logger.error("Provider exhausted retries: %s", last_exception)
        yield {"type": "error", "message": f"Provider error after {1 + self.max_retries} attempts: {last_exception}"}
