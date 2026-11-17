#!/usr/bin/env python3
"""Unit test for the abp_cli ModelAPI wrapper (#3588916, task 2).

No tokens, no real CLI: a fake runner is injected into providers.PROVIDERS so the
test exercises the wrapper's mapping (response -> ModelOutput, runner dict ->
ModelUsage incl. total_cost, exit_code -> error) and registry resolution.

Requires inspect_ai (behavioral extra); SKIPs cleanly without it. Run with the
behavioral venv: ``python evals/inspect/test_provider.py``.
"""
from __future__ import annotations

import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))

try:
    import anyio
    from inspect_ai.model import ChatMessageUser, GenerateConfig, get_model
except ImportError as e:
    print(f"SKIP test_provider: inspect_ai not installed ({e})")
    raise SystemExit(0)

import os.path as _osp  # noqa: E402

import provider  # noqa: E402  registers @modelapi("abp_cli")
import providers  # noqa: E402

failures: list[str] = []


def check(name: str, cond: bool) -> None:
    if not cond:
        failures.append(name)


def fake_runner(response: str, exit_code: int = 0):
    """Build a providers.py-shaped runner returning a canned result."""
    def run(prompt, model=None, cwd=None):
        # The isolation dir is released when generate() returns, so record
        # its existence now, at call time — asserting isdir() afterwards
        # would test the wrong moment.
        run.seen = {
            "prompt": prompt,
            "model": model,
            "cwd": cwd,
            "cwd_existed": isinstance(cwd, str) and _osp.isdir(cwd),
        }
        return {
            "response": response,
            "elapsed": 1.2,
            "exit_code": exit_code,
            "input_tokens": 100,
            "cache_creation_tokens": 20,
            "cache_read_tokens": 30,
            "output_tokens": 40,
            "cost_usd": 0.0042,
        }
    return run


# --- happy path: mapping + underlying-model selection -------------------------
# generate() returns (ModelOutput | Exception, ModelCall).
runner = fake_runner("hello from the skill")
providers.PROVIDERS["claude"] = runner
api = provider.AbpCliAPI("claude/sonnet")
out, call = anyio.run(
    api.generate, [ChatMessageUser(content="how do I open an MR?")], [], "none", GenerateConfig()
)
check("provider/content", out.completion == "hello from the skill")
# output.model is the BARE model_name (no "abp_cli/" prefix), matching Inspect's
# own providers. A prefixed value makes the EEE adapter emit "abp_cli/abp_cli/...".
check("provider/output-model-bare", out.model == "claude/sonnet")
check("provider/usage-input", out.usage.input_tokens == 100)
check("provider/usage-output", out.usage.output_tokens == 40)
check("provider/usage-cache-write", out.usage.input_tokens_cache_write == 20)
check("provider/usage-cache-read", out.usage.input_tokens_cache_read == 30)
# total_tokens includes cache read/write, matching Inspect's own convention.
check("provider/usage-total-tokens", out.usage.total_tokens == 100 + 20 + 30 + 40)
check("provider/usage-total-cost", out.usage.total_cost == 0.0042)
check("provider/underlying-model-passed", runner.seen["model"] == "sonnet")
check("provider/prompt-rendered", "how do I open an MR?" in runner.seen["prompt"])
# ModelCall captures request + CLI metadata (without duplicating the response text).
check("provider/call-request-provider", call.request["provider"] == "claude")
check("provider/call-response-exit", call.response["exit_code"] == 0)
check("provider/call-no-response-dup", "response" not in call.response)

# --- claude with no model: preserve old-runner CLI-default (None), not a pin ---
runner2 = fake_runner("x")
providers.PROVIDERS["claude"] = runner2
api2 = provider.AbpCliAPI("claude")
anyio.run(api2.generate, [ChatMessageUser(content="q")], [], "none", GenerateConfig())
check("provider/claude-omitted-model-is-none", runner2.seen["model"] is None)
# Since #3588977 every provider is confined to a throwaway cwd, claude included.
check("provider/claude-cwd-isolated",
      runner2.seen["cwd_existed"]
      and _osp.basename(runner2.seen["cwd"]).startswith("eval-claude-"))
# The dir is per CALL: released when generate() returns, and a second call on
# the same instance gets a fresh one. Concurrent samples (Inspect runs up to
# max_connections at once) must never share a writable workspace — the on/off
# arms of a control case would otherwise see each other's scratch files.
check("provider/claude-cwd-released", not _osp.isdir(runner2.seen["cwd"]))
first_cwd = runner2.seen["cwd"]
anyio.run(api2.generate, [ChatMessageUser(content="q")], [], "none", GenerateConfig())
check("provider/claude-cwd-per-call", runner2.seen["cwd"] != first_cwd)

# --- non-claude provider with no model: gets its PROVIDER_DEFAULTS ------------
runner3 = fake_runner("y")
providers.PROVIDERS["codex"] = runner3
anyio.run(provider.AbpCliAPI("codex").generate,
          [ChatMessageUser(content="q")], [], "none", GenerateConfig())
check("provider/codex-default-model",
      runner3.seen["model"] == providers.PROVIDER_DEFAULTS["codex"])
# Isolation guard: a full-auto codex/gemini CLI must NOT run in the repo. The
# wrapper hands it a throwaway cwd, never None.
check("provider/codex-cwd-isolated",
      runner3.seen["cwd_existed"]
      and _osp.basename(runner3.seen["cwd"]).startswith("eval-codex-"))

# --- error path: non-zero exit returns an Exception (-> Inspect errors sample) -
providers.PROVIDERS["claude"] = fake_runner("partial", exit_code=1)
err, err_call = anyio.run(
    provider.AbpCliAPI("claude").generate,
    [ChatMessageUser(content="q")], [], "none", GenerateConfig()
)
check("provider/error-is-exception", isinstance(err, Exception) and "exited 1" in str(err))
check("provider/error-keeps-call", err_call.response["exit_code"] == 1)

# --- unknown provider rejected -----------------------------------------------
try:
    provider.AbpCliAPI("does-not-exist")
    check("provider/unknown-raises", False)
except ValueError:
    check("provider/unknown-raises", True)

# --- registry: get_model resolves the abp_cli prefix (provider imported above) -
providers.PROVIDERS["claude"] = fake_runner("ok")
try:
    m = get_model("abp_cli/claude")
    check("provider/registry-resolves", m is not None)
except Exception as e:  # noqa: BLE001
    failures.append(f"registry: {e}")

# --- entry-point discovery (regression guard for the CLI model-resolution bug) -
# The native `inspect eval --model abp_cli/...` CLI resolves the model BEFORE it
# imports the task file, so it relies on the inspect_ai entry point (pyproject)
# to register the provider — NOT on a manual `import provider`. This process
# already imported provider above, so it cannot prove discovery; spawn a CLEAN
# subprocess that resolves abp_cli with no manual import. Only meaningful when
# the editable dist is installed (CI installs it via requirements-behavioral.txt).
import importlib.metadata as _md  # noqa: E402
import subprocess  # noqa: E402

try:
    _md.distribution("abp-inspect")
    _installed = True
except _md.PackageNotFoundError:
    _installed = False

if _installed:
    proc = subprocess.run(
        [sys.executable, "-c",
         "from inspect_ai.model import get_model; "
         "assert get_model('abp_cli/claude') is not None"],
        capture_output=True, text=True,
    )
    check("provider/entrypoint-discovery", proc.returncode == 0)
    if proc.returncode != 0:
        failures.append("entrypoint stderr: " + proc.stderr.strip()[-300:])
else:
    print("NOTE: abp-inspect not installed editable; skipping entry-point discovery guard "
          "(install: pip install -e evals/inspect)")

if failures:
    print(f"FAILED ({len(failures)}):")
    for f in failures:
        print("  -", f)
    raise SystemExit(1)
print("OK — abp_cli provider wrapper maps response/usage/error and registers")
