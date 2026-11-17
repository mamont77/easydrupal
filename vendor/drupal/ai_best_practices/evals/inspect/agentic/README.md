# Agentic pilot: drupal-configuration

The behavioral evals grade what a model *says*, with its tools off. This pilot
grades what an agent *does*: Claude Code or Codex CLI works in a real Drupal
11 site with its tools on, and a grader asks Drupal whether the job was done.

Each case runs twice: arm `on` places the skill where the CLI discovers
project skills by itself (`.claude/skills/` for Claude Code, `.agents/skills/`
for Codex), arm `off` has no skill. `paired_delta.py` reads the result like any
`control` log.

| Case | Task | Graded by (`fixtures/checks/`) |
|---|---|---|
| `schema` | add the missing config schema for `acme_settings` | the config validates against a schema, stored values unchanged |
| `state` | make `acme_cron` remember its last cron run, outside exported config | a cron run writes a fresh `acme`-named state key and no timestamp to config |
| `override` | production API URL on this site, exported config unchanged | effective value is the production URL, active storage and `config/sync` keep the original |

Every grader fails on the untouched site and passes on a known-good fix.

## Run

```bash
pip install -r evals/requirements-behavioral.txt   # Inspect; Docker must be running
python evals/inspect/agentic/run_agentic.py --cli claude --model sonnet --epochs 3
python evals/inspect/agentic/run_agentic.py --cli codex --model gpt-6-luna --case schema
python evals/inspect/paired_delta.py logs/<...>.eval
```

The first run builds the image (`compose.yaml`, about 1.4 GB on top of
`ddev/ddev-webserver:v1.25.1`).

## Login and context

The CLIs run on the operator's subscription login. `compose.yaml` passes
`~/.claude/.credentials.json` and `$CODEX_HOME/auth.json` into the container as
read-only secrets (both files must exist); the solver copies the one it needs
into the agent's empty home with a shell command, so the login never enters the
Inspect log. The agent sees nothing else from the operator's machine. For Codex
the sample metadata keeps the context blocks read from its session log.

Recorded per sample: `skill_listed` (Codex: the skill was in its catalog),
`skill_used` (the agent opened the skill), tool calls, cost (Claude), files
changed, CLI exit code.

## Limits

- Pilot scope: one skill, three cases. Cases are defined in `tasks_agentic.py`,
  not yet in the Eval Commons dataset format.
- Graders are PHP scripts run with Drush; the target home for them is `ai_eval`
  checks on an `ai_proving_ground` fixture, so the same grader runs inside
  Drupal and from Inspect.
- Inspect's own model is `mockllm/model` (the CLI does the work), so EEE
  `model_info` from these logs does not name the agent's model; the sample
  metadata does.
