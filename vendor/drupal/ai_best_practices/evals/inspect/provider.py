#!/usr/bin/env python3
"""Inspect AI ModelAPI that wraps abp's subscription CLI runners (#3588916, task 2).

Inspect normally talks to provider *APIs* (anthropic/openai/google SDKs), which
bills metered tokens. abp's evals run through the subscription CLIs instead
(``claude -p``, ``codex exec``, ``gemini``, plus the Mistral API) to keep the
subscription billing posture. This wraps ``evals/providers.py`` as a custom
Inspect ``ModelAPI`` so the runner gets Inspect's task/scorer/log machinery while
the actual generation still goes through those CLIs.

``@modelapi("abp_cli")`` registers the provider at import time. Two paths import
this file: (1) ``abp_inspect_register`` — the ``inspect_ai`` entry point declared
in ``pyproject.toml`` — which Inspect imports during model resolution so the
native ``inspect eval --model abp_cli/...`` CLI works; and (2) ``tasks.py`` /
``run.py`` directly, for the no-install launcher path. Then::

    get_model("abp_cli/claude")          # provider=claude, model=PROVIDER_DEFAULTS
    get_model("abp_cli/claude/sonnet")   # provider=claude, underlying model=sonnet
    get_model("abp_cli/gemini/gemini-2.5-pro")

``ModelUsage`` carries token counts and ``total_cost`` straight from the runner
dict, so cost/usage survive into the EvalLog.

LIMITATION (documented, a real cost of the wrapper): ``GenerateConfig`` knobs the
subscription CLIs do not expose — ``temperature``, ``seed`` — are NOT forwarded.
Provenance for those is not pinnable here; record only what the CLI exposes.
``inspect_ai`` is a behavioral-only extra; this module is never imported on the
pip-free ``--static`` path.
"""
from __future__ import annotations

import functools
import shutil
import sys
import tempfile
from pathlib import Path

import anyio
from inspect_ai.model import (
    ChatMessage,
    GenerateConfig,
    ModelAPI,
    ModelCall,
    ModelOutput,
    ModelUsage,
)
from inspect_ai.model._registry import modelapi
from inspect_ai.tool import ToolChoice, ToolInfo

EVALS_DIR = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(EVALS_DIR))
import providers  # noqa: E402  (loose-script import after sys.path insert)


def _render_prompt(input: list[ChatMessage]) -> str:
    """Flatten the chat transcript into one stdin prompt for the CLI runners.

    The CLIs take a single prompt, not a message list. The solver puts skill
    context in a system message ahead of the user question, so in-order
    concatenation preserves that ordering. Tool calls are not forwarded — the
    wrapped CLI agent manages its own tools.
    """
    return "\n\n".join(m.text for m in input if m.text)


@modelapi("abp_cli")
class AbpCliAPI(ModelAPI):
    """ModelAPI delegating generation to evals/providers.py CLI runners."""

    def __init__(
        self,
        model_name: str,
        base_url: str | None = None,
        api_key: str | None = None,
        config: GenerateConfig = GenerateConfig(),
        **model_args: object,
    ) -> None:
        super().__init__(model_name, base_url, api_key, [], config)
        provider, _, underlying = model_name.partition("/")
        if provider not in providers.PROVIDERS:
            raise ValueError(
                f"unknown abp provider {provider!r}; "
                f"known: {sorted(providers.PROVIDERS)}"
            )
        self.provider = provider
        if underlying:
            self.underlying_model = underlying
        elif provider == "claude":
            # Match the old run-evals.py: an omitted model means "CLI default"
            # (the recorded drupal-gitlab traces carry model:null). Forcing a
            # pinned model here would silently change which model runs and break
            # an e2e parity run against those traces. Pin explicitly with
            # "abp_cli/claude/<model>" when reproducibility is needed.
            self.underlying_model = None
        else:
            # codex / gemini / mistral runners require an explicit model argument.
            self.underlying_model = providers.PROVIDER_DEFAULTS.get(provider)
        self.runner = providers.PROVIDERS[provider]

    async def generate(
        self,
        input: list[ChatMessage],
        tools: list[ToolInfo],
        tool_choice: ToolChoice,
        config: GenerateConfig,
    ) -> tuple[ModelOutput | Exception, ModelCall]:
        prompt = _render_prompt(input)
        # All agent CLIs run with write permissions (auto / full-auto / yolo),
        # so every CALL gets its own throwaway cwd — never the repo checkout,
        # and never a workspace shared with a concurrently running sample.
        # Inspect runs up to max_connections samples at once; a per-instance
        # dir would let the on/off arms of the same control case observe each
        # other's scratch files and corrupt the paired delta.
        cwd = tempfile.mkdtemp(prefix=f"eval-{self.provider}-")
        try:
            # providers.py runners are blocking subprocess calls; run off the
            # event loop.
            result = await anyio.to_thread.run_sync(
                functools.partial(self.runner, prompt, self.underlying_model, cwd)
            )
        finally:
            shutil.rmtree(cwd, ignore_errors=True)

        # Record the call so request + CLI metadata land in the EvalLog. The
        # response text lives in ModelOutput.completion, so keep it out of the
        # call response to avoid duplicating it.
        request = {
            "provider": self.provider,
            "model": self.underlying_model,
            "prompt": prompt,
        }
        call = ModelCall.create(
            request,
            {k: v for k, v in result.items() if k != "response"},
            time=result.get("elapsed"),
        )

        # A non-zero CLI exit is a provider failure, not a gradable answer. The
        # old runner failed such cases before grading; returning an Exception in
        # the tuple makes Inspect raise it (sample error) rather than scoring an
        # empty/garbage response — e.g. a crash must never "pass" a
        # must_not_contain check on empty output.
        if result["exit_code"] != 0:
            msg = f"{self.provider} CLI exited {result['exit_code']}"
            if result["response"]:
                msg += f": {result['response'][:200]}"
            return RuntimeError(msg), call

        # Inspect's convention: ModelOutput.model is the BARE model name (what
        # follows the provider prefix) — its anthropic provider records
        # "claude-3-5-sonnet-...", not "anthropic/claude-...". self.model_name is
        # already that bare spec ("claude", "claude/sonnet"). Prefixing it with
        # "abp_cli/" here double-counts the provider: the EEE Inspect adapter
        # rebuilds the model path as "<prefix>/<output.model>" and emits
        # "abp_cli/abp_cli/claude". Keep it bare so EEE model_info stays clean.
        output = ModelOutput.from_content(
            model=self.model_name,
            content=result["response"],
        )
        output.usage = ModelUsage(
            input_tokens=result["input_tokens"],
            output_tokens=result["output_tokens"],
            # Match Inspect's own convention (anthropic provider): the total
            # includes cache read/write, not just input + output.
            total_tokens=(
                result["input_tokens"]
                + result.get("cache_creation_tokens", 0)
                + result.get("cache_read_tokens", 0)
                + result["output_tokens"]
            ),
            input_tokens_cache_write=result.get("cache_creation_tokens", 0),
            input_tokens_cache_read=result.get("cache_read_tokens", 0),
            total_cost=result.get("cost_usd", 0.0),
        )
        return output, call
