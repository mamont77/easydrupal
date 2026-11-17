#!/usr/bin/env python3
"""Provider-agnostic CLI runners for eval scripts.

Shared by evals/run-evals.py and evals/compare.py. Each run_* function returns
a dict with a stable shape:

    {
        "response": str,
        "elapsed": float,
        "exit_code": int,
        "input_tokens": int,
        "cache_creation_tokens": int,
        "cache_read_tokens": int,
        "output_tokens": int,
        "cost_usd": float,
        "context": dict,   # what the model was given besides the prompt (see below)
    }

Use PROVIDERS[name] to pick the runner and PROVIDER_DEFAULTS[name] for the
default model string. ISOLATION_MODES[name] gives a human-readable description
of the isolation surface each provider uses.

The agent CLIs answer only: their tools are switched off, so the model answers
from what it knows plus the prompt. They must also not see the operator's own
setup (global instructions, installed skills, memories), or a "no skill" answer
is really an answer with the operator's skills. Where a CLI writes a session
log, the runner reads back what the model was given into result["context"]
and raises ContextLeakError when it finds personal context.
"""

from __future__ import annotations

import json
import os
import re
import shutil
import signal
import subprocess
import sys
import tempfile
import time
from contextlib import contextmanager
from pathlib import Path

PROVIDER_DEFAULTS = {
    "claude": "sonnet",
    "codex": "gpt-5.4",
    "gemini": "gemini-2.5-pro",
    "mistral": "codestral-latest",
    # A fixed model, as for claude: "auto" lets Kiro pick per task, so runs would not compare.
    # Kiro's model ids are listed by `kiro-cli chat --list-models`.
    "kiro": "claude-sonnet-5",
}

MODEL_PRICING = {
    "gpt-5.4": (2.00, 8.00),
    "gpt-4.1-mini": (0.40, 1.60),
    "gemini-2.5-pro": (1.25, 10.00),
    "gemini-2.5-flash": (0.15, 0.60),
    "devstral-2": (0.40, 2.00),
    "codestral-latest": (0.30, 0.90),
}

ISOLATION_MODES = {
    "claude": "fresh tempdir cwd + --setting-sources '' + --tools '' + --disable-slash-commands "
              "(answer only: no tools, no skills, no user config)",
    "codex": "fresh tempdir cwd + throwaway HOME/CODEX_HOME holding only the login + "
             "--sandbox read-only with shell, web search, skills, hooks, plugins, apps, browser, "
             "computer use and memories off (answer only); context read back from the "
             "session log, ContextLeakError on personal context",
    "gemini": "fresh tempdir cwd + --approval-mode yolo",
    "mistral": "direct API (no CLI config)",
    "kiro": "fresh tempdir cwd + empty throwaway KIRO_HOME (kiro-cli 2.31+) + --no-interactive + "
            "--trust-tools= (answer only: no tool trusted, so no commands, file reads or web); "
            "context read back from the session log and command list, ContextLeakError on "
            "steering, skills or agents that are not Kiro's own",
}

# The CLIs are coding agents. With their tools off, a "create a form" prompt
# still makes them reach for a tool, and the reply is a fake tool call or "I
# can't write files" instead of an answer. Both arms get the same note, so it
# sets the answer format without favouring either.
ANSWER_ONLY_NOTE = ("\n\nYou have no tools and no files in this session. Answer in your reply, "
                    "writing out any code or file contents there.")


def _isolated_cwd(provider: str, cwd: str | None) -> str:
    """Resolve the working directory for an agent CLI subprocess.

    Defaults to a fresh throwaway directory: the CLIs run with write
    permissions, so they must never start inside the repo checkout, where the
    agent under eval could modify the rubrics and checks that grade it.
    Callers that need a specific working directory pass cwd explicitly.
    """
    if cwd:
        return cwd
    return tempfile.mkdtemp(prefix=f"eval-{provider}-")


def estimate_cost(model: str, input_tokens: int, output_tokens: int) -> float:
    """Conservative cost estimate. Cache tokens are charged at full input rate.

    Claude reports its own cost via total_cost_usd, so this is only used for
    providers that do not report cost natively (codex, gemini, mistral).
    Returns 0.0 when the model is not in MODEL_PRICING.
    """
    pricing = MODEL_PRICING.get(model)
    if not pricing:
        return 0.0
    return round((input_tokens * pricing[0] + output_tokens * pricing[1]) / 1_000_000, 6)


def clean_env() -> dict:
    """Strip Claude Code nesting variables so sub-agent CLIs do not recurse."""
    env = {**os.environ}
    env.pop("CLAUDECODE", None)
    env.pop("CLAUDE_CODE_ENTRYPOINT", None)
    return env


def _default_result() -> dict:
    return {
        "response": "",
        "elapsed": 0.0,
        "exit_code": -1,
        "input_tokens": 0,
        "cache_creation_tokens": 0,
        "cache_read_tokens": 0,
        "output_tokens": 0,
        "cost_usd": 0.0,
        "context": {},
    }


class ContextLeakError(RuntimeError):
    """The CLI under eval was given the operator's own context.

    Its answers would measure the operator's setup (their instructions, skills
    or memories), not the skill under eval, so the runner refuses to return
    them rather than let them be graded.
    """


def run_claude(prompt: str, model: str | None = None, cwd: str | None = None) -> dict:
    """Run claude -p with JSON output and return parsed result.

    When model is falsy, --model is omitted so the user's claude CLI default applies.
    """
    start = time.monotonic()
    result = _default_result()
    cmd = ["claude", "-p", "-"]
    if model:
        cmd += ["--model", model]
    cmd += [
        "--no-session-persistence",
        "--permission-mode", "auto",
        "--output-format", "json",
        "--setting-sources", "",
        "--strict-mcp-config",
        # Answer only: no tools (so no searching the disk for the skill) and
        # no skills, the operator's included.
        "--tools", "",
        "--disable-slash-commands",
    ]
    try:
        proc = subprocess.run(
            cmd,
            input=prompt + ANSWER_ONLY_NOTE,
            capture_output=True,
            text=True,
            timeout=300,
            env=clean_env(),
            cwd=_isolated_cwd("claude", cwd),
        )
        result["elapsed"] = round(time.monotonic() - start, 1)
        result["exit_code"] = proc.returncode
        raw = proc.stdout.strip()

        try:
            data = json.loads(raw)
            if isinstance(data, list):
                result_obj = next(
                    (item for item in data
                     if isinstance(item, dict) and item.get("type") == "result"),
                    {},
                )
            else:
                result_obj = data
            result["response"] = result_obj.get("result", "")
            result["cost_usd"] = result_obj.get("total_cost_usd", 0.0) or 0.0
            usage = result_obj.get("usage", {})
            result["input_tokens"] = usage.get("input_tokens", 0) or 0
            result["cache_creation_tokens"] = usage.get("cache_creation_input_tokens", 0) or 0
            result["cache_read_tokens"] = usage.get("cache_read_input_tokens", 0) or 0
            result["output_tokens"] = usage.get("output_tokens", 0) or 0
        except json.JSONDecodeError:
            result["response"] = raw

    except FileNotFoundError:
        return result
    except subprocess.TimeoutExpired:
        result["elapsed"] = round(time.monotonic() - start, 1)

    return result


# Codex features that give the agent tools or pull in the operator's setup.
# Turned off so the model answers from what it knows plus the prompt.
CODEX_DISABLED_FEATURES = (
    "shell_tool", "skill_search", "hooks", "plugins", "apps",
    "browser_use", "computer_use", "memories",
)
# With one of these set, Codex authenticates without a login file.
CODEX_KEY_ENV = ("CODEX_API_KEY", "OPENAI_API_KEY")

# Context blocks Codex adds to a session, by the marker each one starts with.
# Checked in order: one message can carry several blocks, and the first match
# names it. "agents_md" is any AGENTS.md (global or from the working dir).
_CODEX_BLOCK_MARKERS = (
    ("# AGENTS.md instructions", "agents_md"),
    ("<user_instructions>", "user_instructions"),
    ("<memories", "memories"),
    ("<skills_instructions>", "skills_list"),
    ("<multi_agent", "multi_agent"),
    ("<environment_context>", "environment"),
)
_CODEX_PERSONAL_BLOCKS = {"agents_md", "user_instructions", "memories"}
# One entry of the available-skills list ("- name: description ... SKILL.md").
_CODEX_SKILL_ENTRY = re.compile(r"^- [\w:.-]+: .*SKILL\.md", re.M)


def _codex_source_home() -> Path:
    return Path(os.environ.get("CODEX_HOME") or Path.home() / ".codex")


def _write_private(path: Path, data: bytes) -> None:
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(fd, "wb") as f:
        f.write(data)


@contextmanager
def _isolated_codex_home():
    """Yield (home, codex_home): a throwaway HOME whose .codex holds only the login.

    Codex loads the operator's ~/.codex/AGENTS.md, installed skills and config
    into every call, and no flag skips them (codex-cli 0.162: neither
    --ignore-user-config nor the feature switches do). A fresh HOME and
    CODEX_HOME per call is the only clean context. The login file is copied in
    (mode 0600) unless an API key is set, and the directory is deleted after the
    call. If Codex refreshed the login during the call, the new login is written
    back, but only when the operator's file is still the one copied, so their
    own Codex keeps working.
    """
    source_auth = _codex_source_home() / "auth.json"
    root = Path(tempfile.mkdtemp(prefix="eval-codex-home-"))
    codex_home = root / ".codex"
    codex_home.mkdir(mode=0o700)
    copy_auth = codex_home / "auth.json"
    seeded = None
    try:
        if not any(os.environ.get(k) for k in CODEX_KEY_ENV) and source_auth.is_file():
            seeded = source_auth.read_bytes()
            _write_private(copy_auth, seeded)
        yield root, codex_home
    finally:
        try:
            if seeded is not None and copy_auth.is_file():
                refreshed = copy_auth.read_bytes()
                if refreshed != seeded and source_auth.read_bytes() == seeded:
                    staged = source_auth.with_name("auth.json.eval-refresh")
                    _write_private(staged, refreshed)
                    os.replace(staged, source_auth)
        finally:
            shutil.rmtree(root, ignore_errors=True)


def _codex_context(codex_home: Path, prompt: str, ignore: list[str]) -> dict:
    """Read back from Codex's session log what the model was given.

    Returns {"blocks": [{"role", "kind", "chars"}], "leaks": [...]}. A block is
    a leak when it is personal by kind (AGENTS.md, user instructions, memories)
    or when it names the operator's home directory. ``ignore`` lists paths the
    runner created (the throwaway home, the working dir), which may sit under
    the real home and are not a leak.
    """
    logs = sorted(codex_home.glob("sessions/**/rollout-*.jsonl"))
    if not logs:
        return {"blocks": [], "leaks": ["no session log, so the context cannot be checked"]}
    real_home = str(Path.home())
    blocks, leaks = [], []
    for line in logs[-1].read_text().splitlines():
        try:
            event = json.loads(line)
        except json.JSONDecodeError:
            continue
        payload = event.get("payload") or {}
        if event.get("type") == "session_meta":
            base = payload.get("base_instructions") or {}
            text = base.get("text", "") if isinstance(base, dict) else str(base)
            blocks.append({"role": "system", "kind": "codex_base_instructions", "chars": len(text)})
            continue
        if not (event.get("type") == "response_item" and payload.get("type") == "message"):
            continue
        role = payload.get("role")
        if role not in ("developer", "user", "system"):
            continue
        text = "".join(c.get("text", "") for c in payload.get("content", []) if isinstance(c, dict))
        if role == "user" and text.strip() == prompt.strip():
            kind = "prompt"
        else:
            kind = next((k for marker, k in _CODEX_BLOCK_MARKERS if marker in text), "other")
        blocks.append({"role": role, "kind": kind, "chars": len(text)})
        if kind == "prompt":
            continue
        scrubbed = text
        for path in ignore:
            scrubbed = scrubbed.replace(path, "")
        if kind in _CODEX_PERSONAL_BLOCKS:
            leaks.append(f"{role} message: {kind}")
        elif kind == "skills_list" and _CODEX_SKILL_ENTRY.search(text):
            n = len(_CODEX_SKILL_ENTRY.findall(text))
            leaks.append(f"{role} message: skills list names {n} skill(s), so 'no skill' is not skill-free")
        elif real_home and real_home in scrubbed:
            leaks.append(f"{role} message ({kind}) names {real_home}")
    return {"blocks": blocks, "leaks": leaks}


def run_codex(prompt: str, model: str, cwd: str | None = None) -> dict:
    """Run codex exec answer-only, in a throwaway home, and audit its context.

    Raises ContextLeakError when the session log shows personal context.
    """
    start = time.monotonic()
    result = _default_result()
    prompt += ANSWER_ONLY_NOTE
    work_dir = _isolated_cwd("codex", cwd)
    with _isolated_codex_home() as (home, codex_home):
        env = {**clean_env(), "HOME": str(home), "CODEX_HOME": str(codex_home)}
        cmd = ["codex", "exec", "--json", "--sandbox", "read-only", "--skip-git-repo-check"]
        for feature in CODEX_DISABLED_FEATURES:
            cmd += ["--disable", feature]
        # Codex lists its own bundled skills (skill-creator, imagegen, ...) even in
        # an empty home. A one-token catalog budget leaves that list empty.
        cmd += ["-c", "skills.max_context_tokens=1"]
        # Web search is a config setting, not a feature, and is on by default:
        # without this, answer-only Codex looks things up on drupal.org.
        cmd += ["-c", 'web_search="disabled"']
        cmd += ["-m", model, "-"]
        try:
            proc = subprocess.run(
                cmd,
                input=prompt,
                capture_output=True,
                text=True,
                timeout=300,
                env=env,
                cwd=work_dir,
            )
        except FileNotFoundError:
            return result
        except subprocess.TimeoutExpired:
            result["elapsed"] = round(time.monotonic() - start, 1)
            return result
        result["elapsed"] = round(time.monotonic() - start, 1)
        result["exit_code"] = proc.returncode

        messages = []
        for line in proc.stdout.splitlines():
            line = line.strip()
            if not line:
                continue
            try:
                event = json.loads(line)
            except json.JSONDecodeError:
                continue

            item = event.get("item", {})
            if isinstance(item, dict) and item.get("type") == "agent_message":
                text = item.get("text")
                if isinstance(text, str) and text:
                    messages.append(text)

            if event.get("type") == "turn.completed":
                usage = event.get("usage", {}) or {}
                result["input_tokens"] = usage.get("input_tokens", 0) or 0
                result["cache_creation_tokens"] = usage.get("cache_creation_input_tokens", 0) or 0
                result["cache_read_tokens"] = usage.get("cached_input_tokens", 0) or 0
                result["output_tokens"] = usage.get("output_tokens", 0) or 0

        result["response"] = "\n\n".join(messages)
        if proc.returncode == 0 and not messages:
            print("  WARNING: codex exited 0 but no agent_message found in NDJSON",
                  file=sys.stderr)
        if proc.returncode != 0 and not (codex_home / "auth.json").exists() \
                and not any(os.environ.get(k) for k in CODEX_KEY_ENV):
            print(f"  WARNING: codex has no login: no {_codex_source_home() / 'auth.json'} "
                  f"and none of {', '.join(CODEX_KEY_ENV)} set", file=sys.stderr)
        result["cost_usd"] = estimate_cost(
            model,
            result["input_tokens"] + result["cache_creation_tokens"] + result["cache_read_tokens"],
            result["output_tokens"],
        )
        if proc.returncode == 0:
            result["context"] = _codex_context(codex_home, prompt, [str(home), work_dir])

    if result["context"].get("leaks"):
        raise ContextLeakError(
            "codex was given personal context, so this answer cannot be graded: "
            + "; ".join(result["context"]["leaks"])
        )
    return result


def run_gemini(prompt: str, model: str, cwd: str | None = None) -> dict:
    start = time.monotonic()
    result = _default_result()
    try:
        proc = subprocess.run(
            [
                "gemini",
                "--output-format", "json",
                "--model", model,
                "--approval-mode", "yolo",
                "-p", "-",
            ],
            input=prompt,
            capture_output=True,
            text=True,
            timeout=180,
            env=clean_env(),
            cwd=_isolated_cwd("gemini", cwd),
        )
        result["elapsed"] = round(time.monotonic() - start, 1)
        result["exit_code"] = proc.returncode
        raw = proc.stdout.strip()

        try:
            data = json.loads(raw) if raw else {}
        except json.JSONDecodeError:
            result["response"] = raw
            return result

        result["response"] = data.get("response", "") or ""
        stats_models = ((data.get("stats") or {}).get("models") or {})
        for stats in stats_models.values():
            tokens = (stats or {}).get("tokens", {}) or {}
            result["input_tokens"] += tokens.get("input", 0) or 0
            result["output_tokens"] += tokens.get("candidates", 0) or 0
            result["cache_read_tokens"] += tokens.get("cached", 0) or 0

        result["cost_usd"] = estimate_cost(
            model,
            result["input_tokens"] + result["cache_read_tokens"],
            result["output_tokens"],
        )
    except FileNotFoundError:
        return result
    except subprocess.TimeoutExpired:
        result["elapsed"] = round(time.monotonic() - start, 1)

    return result


def run_mistral(prompt: str, model: str, cwd: str | None = None) -> dict:
    """Call the Mistral/Codestral API directly via urllib. No CLI dependency.

    Uses CODESTRAL_API_KEY or MISTRAL_API_KEY env var.
    Default endpoint: https://codestral.mistral.ai/v1/chat/completions
    Override with MISTRAL_API_BASE env var. The cwd parameter is accepted for
    signature consistency with the CLI runners but is unused (no subprocess).
    """
    import urllib.error
    import urllib.request

    start = time.monotonic()
    result = _default_result()
    api_key = os.environ.get("CODESTRAL_API_KEY") or os.environ.get("MISTRAL_API_KEY", "")
    api_base = os.environ.get("MISTRAL_API_BASE", "https://codestral.mistral.ai/v1")
    if not api_key:
        result["response"] = ""
        result["exit_code"] = 2
        print("  WARNING: no Mistral API key found (set CODESTRAL_API_KEY or MISTRAL_API_KEY)", file=sys.stderr)
        return result
    try:
        body = json.dumps({
            "model": model,
            "messages": [{"role": "user", "content": prompt}],
            "temperature": 0.2,
        }).encode()
        req = urllib.request.Request(
            f"{api_base}/chat/completions",
            data=body,
            headers={
                "Authorization": f"Bearer {api_key}",
                "Content-Type": "application/json",
            },
        )
        with urllib.request.urlopen(req, timeout=180) as resp:
            data = json.loads(resp.read())
        result["elapsed"] = round(time.monotonic() - start, 1)
        result["exit_code"] = 0

        choices = data.get("choices", [])
        if choices:
            result["response"] = choices[0].get("message", {}).get("content", "")

        usage = data.get("usage", {})
        result["input_tokens"] = usage.get("prompt_tokens", 0) or 0
        result["output_tokens"] = usage.get("completion_tokens", 0) or 0
        result["cost_usd"] = estimate_cost(model, result["input_tokens"], result["output_tokens"])
    except (urllib.error.URLError, urllib.error.HTTPError) as e:
        result["elapsed"] = round(time.monotonic() - start, 1)
        result["response"] = f"[API error: {type(e).__name__}]"
    except TimeoutError:
        result["elapsed"] = round(time.monotonic() - start, 1)

    return result


# Routine stderr from kiro-cli: logs and tool activity (tools are untrusted, so denials are expected).
_KIRO_ROUTINE_STDERR = ("[INFO]", "[DEBUG]", "[tool]", "[denied]")
# Node's notice that Kiro's agent server uses its experimental SQLite module (kiro-cli 2.31).
_KIRO_NODE_NOTICE = re.compile(r"^\[ERROR\] \(node:\d+\) ExperimentalWarning: |^\(Use `node --trace-warnings")


def _kiro_context(kiro_home: Path, events: list[dict]) -> dict:
    """Read back what Kiro was given besides the prompt.

    Returns {"blocks": [...], "leaks": [...]}. Steering comes from the session
    log under KIRO_HOME, skills and custom agents from the command list in the
    stream. Kiro's bundled agents are not a leak; steering, skills or agents
    from anywhere else are, since the "no skill" arm must not see them.
    """
    logs = sorted(kiro_home.glob("sessions/*/*/messages.jsonl"))
    if not logs:
        return {"blocks": [], "leaks": ["no session log under KIRO_HOME (kiro-cli 2.31 or later "
                                        "writes one), so the context cannot be checked"]}
    blocks, steering = [], {}
    for line in logs[-1].read_text().splitlines():
        try:
            payload = json.loads(line).get("payload") or {}
        except json.JSONDecodeError:
            continue
        if payload.get("type") == "session_start":
            blocks.append({"role": "system", "kind": "kiro_base_instructions",
                           "chars": len(payload.get("content", ""))})
            for doc in payload.get("steeringDocuments") or []:
                steering[doc.get("id")] = f"{doc.get('displayName')} ({doc.get('scope')})"
        elif payload.get("type") == "steering_inclusion":
            for doc_id in payload.get("documents") or []:
                steering.setdefault(doc_id, doc_id)
    leaks = [f"steering {name}" for name in steering.values()]
    blocks += [{"role": "system", "kind": "steering", "name": name} for name in steering.values()]
    commands = {}
    for event in events:
        update = (event.get("data") or {}).get("update") or {}
        if update.get("sessionUpdate") == "available_commands_update":
            for command in update.get("availableCommands") or []:
                kiro = (command.get("_meta") or {}).get("kiro") or {}
                origin = ((kiro.get("resource") or {}).get("source") or {}).get("origin")
                commands[command.get("name")] = (kiro.get("type"), origin)
    for name, (kind, origin) in commands.items():
        blocks.append({"role": "system", "kind": kind, "name": name, "origin": origin})
        if origin != "bundled":
            leaks.append(f"{kind} {name} ({origin})")
    return {"blocks": blocks, "leaks": leaks}


def run_kiro(prompt: str, model: str, cwd: str | None = None) -> dict:
    """Run Kiro CLI (v3 agent engine) answer-only, with an empty KIRO_HOME, and audit its context.

    Kiro reports credits, not tokens, so tokens and cost stay at zero.
    Authenticate first with `kiro-cli login` (the login lives outside KIRO_HOME),
    or set KIRO_API_KEY for headless runs. No tool is trusted, so the agent can
    answer but cannot run commands, read files or use the web.

    The operator's ~/.kiro steering, skills and agents load into every call, so
    each call gets a throwaway KIRO_HOME (honoured since kiro-cli 2.31; 2.16
    still read ~/.kiro). Raises ContextLeakError when the session log or the
    command list shows steering, skills or agents that are not Kiro's own.

    The prompt goes on stdin, so it has no argv size limit and its "---" skill
    frontmatter is not parsed as an option. Kiro writes logs and tool activity
    on stderr; any other line is printed as a warning.
    """
    start = time.monotonic()
    result = _default_result()
    prompt += ANSWER_ONLY_NOTE
    kiro_home = Path(tempfile.mkdtemp(prefix="eval-kiro-home-"))
    # Older kiro-cli releases exit after answering but leave their agent server running
    # with our stdout inherited, so read the answer from a file (a pipe would not close)
    # and stop the whole process group afterwards.
    try:
        with tempfile.TemporaryFile("w+") as stdin, tempfile.TemporaryFile("w+") as out, \
                tempfile.TemporaryFile("w+") as err:
            stdin.write(prompt)
            stdin.seek(0)
            try:
                proc = subprocess.Popen(
                    [
                        "kiro-cli", "chat",
                        "--agent-engine", "v3",
                        "--no-interactive",
                        "--trust-tools=",
                        "--output-format", "stream-json",
                        "--model", model,
                    ],
                    stdin=stdin,
                    stdout=out,
                    stderr=err,
                    env={**clean_env(), "KIRO_HOME": str(kiro_home)},
                    cwd=_isolated_cwd("kiro", cwd),
                    start_new_session=True,
                )
            except FileNotFoundError:
                return result
            try:
                result["exit_code"] = proc.wait(timeout=300)
            except subprocess.TimeoutExpired:
                pass
            finally:
                try:
                    os.killpg(proc.pid, signal.SIGKILL)
                except ProcessLookupError:
                    pass
                proc.wait()
            result["elapsed"] = round(time.monotonic() - start, 1)
            out.seek(0)
            events = []
            for line in out:
                try:
                    events.append(json.loads(line))
                except json.JSONDecodeError:
                    continue
            err.seek(0)
            for line in err:
                line = line.strip()
                if line and not line.startswith(_KIRO_ROUTINE_STDERR) and not _KIRO_NODE_NOTICE.match(line):
                    # Error lines can embed the whole request, prompt included.
                    print(f"  WARNING: kiro: {line[:300]}", file=sys.stderr)
        # runFinished carries the whole answer; the chunks are the fallback if it is cut short.
        finished = next((e.get("data") or {} for e in reversed(events) if e.get("type") == "runFinished"), {})
        if finished.get("finalText") and not finished.get("finalTextTruncated"):
            result["response"] = finished["finalText"].strip()
        else:
            result["response"] = "".join(
                ((u.get("content") or {}).get("text") or "")
                for u in (((e.get("data") or {}).get("update") or {}) for e in events)
                if u.get("sessionUpdate") == "agent_message_chunk"
            ).strip()
        if result["exit_code"] == 0:
            if not result["response"]:
                print("  WARNING: kiro exited 0 but no answer found in its stream-json output",
                      file=sys.stderr)
            result["context"] = _kiro_context(kiro_home, events)
    finally:
        shutil.rmtree(kiro_home, ignore_errors=True)

    if result["context"].get("leaks"):
        raise ContextLeakError(
            "kiro was given steering, skills or agents beyond its own, so this answer cannot be graded: "
            + "; ".join(result["context"]["leaks"])
        )
    return result


PROVIDERS = {
    "claude": run_claude,
    "codex": run_codex,
    "gemini": run_gemini,
    "mistral": run_mistral,
    "kiro": run_kiro,
}
