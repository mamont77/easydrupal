"""Agentic pilot: does a skill help a CLI agent do real work in a Drupal codebase?

Each case is a small task in a Drupal 11 site inside a Docker sandbox
(Dockerfile, compose.yaml). The agent (Claude Code or Codex CLI, on the
operator's subscription login) runs with its tools on. Arm ``on`` places the
skill where that CLI discovers project skills; arm ``off`` has no skill. The
scorer runs a grader inside the same container that asks Drupal whether the
job was done (fixtures/checks/*.php); wording is never graded.

    python evals/inspect/agentic/run_agentic.py --cli claude --model sonnet

The operator's login reaches the container as a read-only compose secret and
is copied into the agent's otherwise empty home with a shell command, so its
contents never enter the Inspect log. Pilot scope: drupal-configuration only.
"""

from __future__ import annotations

import json
import sys
import tempfile
from pathlib import Path

from inspect_ai import Task, task
from inspect_ai.dataset import MemoryDataset, Sample
from inspect_ai.model import ModelOutput
from inspect_ai.scorer import CORRECT, INCORRECT, Score, Target, accuracy, scorer, stderr
from inspect_ai.solver import Generate, TaskState, solver
from inspect_ai.util import sandbox

HERE = Path(__file__).resolve().parent
REPO = HERE.parents[2]
sys.path.insert(0, str(REPO / "evals"))
import providers  # noqa: E402  (loose-script import after sys.path insert)

SITE = "/home/agent/site"
PATH = f"{SITE}/vendor/bin:/usr/local/bin:/usr/bin:/bin"
SUFFIX = ("\n\nYou are working in a Drupal 11 codebase in the current directory. "
          "Drush is available as vendor/bin/drush. Make the change; do not only describe it.")

# case id -> prompt; the grader is fixtures/checks/<case id>.php
CASES = {
    "schema": (
        "The custom module acme_settings (web/modules/custom/acme_settings) saves its "
        "settings, but Drupal cannot validate them: they have no configuration schema. "
        "Fix that, keeping the existing settings and their current values."
    ),
    "state": (
        "The custom module acme_cron (web/modules/custom/acme_cron) must remember when "
        "its cron run last happened, so an admin page can show it later. That value must "
        "not end up in the exported site configuration. Implement it."
    ),
    "override": (
        "This Drupal site is the production server. Here the acme_settings API URL must be "
        "https://api.prod.example.com, while the value in the exported configuration "
        "(config/sync) must stay https://api.example.com. Set this up."
    ),
}

# Where each CLI discovers project skills on its own.
SKILL_DIRS = {"claude": ".claude/skills", "codex": ".agents/skills"}
LOGIN = {
    "claude": "mkdir -p ~/.claude && cp /run/secrets/claude_credentials ~/.claude/.credentials.json "
              "&& chmod 600 ~/.claude/.credentials.json",
    "codex": "mkdir -p ~/.codex && cp /run/secrets/codex_auth ~/.codex/auth.json "
             "&& chmod 600 ~/.codex/auth.json",
}


def _samples() -> list[Sample]:
    return [
        Sample(id=f"{cid}::{arm}", input=prompt + SUFFIX,
               metadata={"case_id": cid, "arm": arm})
        for cid, prompt in CASES.items()
        for arm in ("on", "off")
    ]


def _claude_cmd(prompt: str, model: str) -> list[str]:
    return ["claude", "-p", prompt, "--model", model, "--output-format", "json",
            "--dangerously-skip-permissions"]


def _codex_cmd(prompt: str, model: str) -> list[str]:
    # The container is the sandbox, so Codex's own sandbox is bypassed.
    return ["codex", "exec", "--json", "--dangerously-bypass-approvals-and-sandbox",
            "--skip-git-repo-check", "-m", model, prompt]


async def _claude_trace(sb, skill: str) -> dict:
    """Tool calls from the Claude Code transcript, and whether the skill was opened."""
    found = await sb.exec(["bash", "-c", "ls -t ~/.claude/projects/*/*.jsonl 2>/dev/null | head -1"], user="agent")
    path = found.stdout.strip()
    tools, skill_used = [], False
    if path:
        for line in (await sb.read_file(path)).splitlines():
            try:
                msg = json.loads(line).get("message") or {}
            except json.JSONDecodeError:
                continue
            for item in msg.get("content") or []:
                if isinstance(item, dict) and item.get("type") == "tool_use":
                    tools.append(item.get("name"))
                    blob = json.dumps(item.get("input") or {})
                    if item.get("name") == "Skill" or f"skills/{skill}" in blob:
                        skill_used = True
    return {"tool_calls": len(tools), "tools": sorted(set(tools)), "skill_used": skill_used}


async def _codex_trace(sb, stdout: str, prompt: str, skill: str) -> dict:
    """Commands from Codex's event stream, the skill check, and its context audit."""
    commands = []
    for line in stdout.splitlines():
        try:
            event = json.loads(line)
        except json.JSONDecodeError:
            continue
        item = event.get("item") or {}
        # Each command arrives as item.started and item.completed; count it once.
        if event.get("type") == "item.completed" and item.get("type") == "command_execution":
            commands.append(item.get("command") or "")
    found = await sb.exec(["bash", "-c", "ls -t ~/.codex/sessions/*/*/*/rollout-*.jsonl 2>/dev/null | head -1"],
                          user="agent")
    context, listed = {}, False
    if found.stdout.strip():
        rollout = await sb.read_file(found.stdout.strip())
        listed = f"- {skill}:" in rollout  # the skill's entry in Codex's skills catalog
        with tempfile.TemporaryDirectory() as tmp:
            local = Path(tmp) / "sessions" / "x" / "rollout-x.jsonl"
            local.parent.mkdir(parents=True)
            local.write_text(rollout)
            context = providers._codex_context(Path(tmp), prompt, [])
    return {"tool_calls": len(commands),
            "skill_listed": listed,
            "skill_used": any(f"{skill}/SKILL.md" in c for c in commands),
            "context_blocks": [f"{b['kind']}:{b['chars']}" for b in context.get("blocks", [])]}


def _final_text(cli: str, stdout: str) -> tuple[str, float]:
    if cli == "claude":
        try:
            data = json.loads(stdout)
            return data.get("result", "") or "", float(data.get("total_cost_usd") or 0.0)
        except json.JSONDecodeError:
            return stdout[-2000:], 0.0
    messages = []
    for line in stdout.splitlines():
        try:
            item = json.loads(line).get("item") or {}
        except json.JSONDecodeError:
            continue
        if item.get("type") == "agent_message" and item.get("text"):
            messages.append(item["text"])
    return (messages[-1] if messages else ""), 0.0


@solver
def cli_agent(cli: str, model: str, skill: str, timeout: int = 900):
    if cli not in LOGIN:
        raise ValueError(f"unknown cli {cli!r}; known: {sorted(LOGIN)}")

    async def solve(state: TaskState, generate: Generate) -> TaskState:
        sb = sandbox()
        login = await sb.exec(["bash", "-c", LOGIN[cli]], user="agent")
        if not login.success:
            raise RuntimeError(f"{cli} login copy failed: {login.stderr[-300:]}")
        if state.metadata["arm"] == "on":
            src = REPO / "skills" / skill
            for f in sorted(p for p in src.rglob("*") if p.is_file()):
                await sb.write_file(f"{SITE}/{SKILL_DIRS[cli]}/{skill}/{f.relative_to(src)}", f.read_text())
        cmd = (_claude_cmd if cli == "claude" else _codex_cmd)(state.input_text, model)
        run = await sb.exec(cmd, cwd=SITE, timeout=timeout, user="agent", env={"PATH": PATH})
        text, cost = _final_text(cli, run.stdout)
        trace = await (_claude_trace(sb, skill) if cli == "claude"
                       else _codex_trace(sb, run.stdout, state.input_text, skill))
        changed = await sb.exec(["git", "status", "--porcelain"], cwd=SITE, user="agent")
        state.metadata.update({
            "cli": cli, "cli_model": model, "exit_code": run.returncode, "cost_usd": cost,
            # Code and config the agent touched; the skill copy and the SQLite DB are not its work.
            "files_changed": [ln[3:] for ln in changed.stdout.splitlines()
                              if not ln[3:].startswith((".claude/", ".agents/")) and ".ht.sqlite" not in ln],
            **trace,
        })
        state.output = ModelOutput.from_content(model=f"{cli}/{model}", content=text)
        return state

    return solve


@scorer(metrics=[accuracy(), stderr()])
def drupal_check():
    """Ask Drupal, inside the sandbox, whether the task was done."""

    async def score(state: TaskState, target: Target) -> Score:
        check = state.metadata["case_id"]
        r = await sandbox().exec(
            ["bash", "-c", f"cd {SITE} && vendor/bin/drush cr -q && vendor/bin/drush php:script "
                           f"/home/agent/checks/{check}.php"],
            user="agent", timeout=300, env={"PATH": PATH})
        try:
            verdict = json.loads(r.stdout.strip().splitlines()[-1])
        except (json.JSONDecodeError, IndexError):
            return Score(value=INCORRECT,
                         explanation=f"grader could not run (exit {r.returncode}): {r.stderr.strip()[-300:]}")
        return Score(value=CORRECT if verdict.get("pass") else INCORRECT,
                     explanation=verdict.get("detail", ""))

    return score


@task
def config_agentic(cli: str = "claude", model: str = "sonnet",
                   skill: str = "drupal-configuration") -> Task:
    return Task(
        dataset=MemoryDataset(samples=_samples(), name=f"{skill}-agentic"),
        solver=cli_agent(cli, model, skill),
        scorer=drupal_check(),
        sandbox=("docker", str(HERE / "compose.yaml")),
        name=f"abp-agentic-{skill}-{cli}",
    )
