# AGENTS.md — ai_best_practices contributor context

> This file is for contributors developing this package.
> If you installed it via Composer, your AGENTS.md was written by the plugin.

## What this repo is

A Composer plugin (`drupal/ai_best_practices`) that copies AI guidance files
into Drupal projects. Two largely independent halves:

- **`skills/` + `evals/`** — content (Markdown + JSON). No
  build step. The most common contribution type.
- **`src/`** — the PHP Composer plugin. Changes here require PHPUnit tests and
  `composer cs-fix` before opening an MR.

## Skill authoring rules

Before adding a skill, read `docs/skill-authoring.md` — it defines when something
belongs in a skill vs. `AGENTS.md` vs. documentation, the admission checklist,
good and bad candidates, and the "skills are procedural, docs are declarative" rule.

Frontmatter must follow the Agent Skills spec. Do not invent custom keys such as
`status:`, `drupal-version:`, or `last-reviewed:`. Although `metadata:` is part
of the spec, it should not be used as a catch-all for custom fields.

## New skill checklist

When adding a skill, all of these need to happen — the CI and installer are
independent and won't remind you:

- [ ] `skills/<name>/SKILL.md` — frontmatter valid (see rules below)
- [ ] `evals/<name>/static-checks.json` — at minimum a `file_exists` + `agent_skill_frontmatter` check
- [ ] `evals/<name>/dataset.yaml` + `rubrics.yaml` — behavioral cases in the Eval
      Commons model: questions (`input`, `criteria`, `rubric_ref`) plus one rubric
      per case (`must_contain_any` / `must_not_contain` / `php_lint` /
      `markdown_structure` checks, `scoring.combine: all_pass`). Can be thin initially.
- [ ] `evals/<name>/README.md` — describe what the evals cover

Skills are auto-discovered: anything placed under `skills/<name>/` ships to
consumers automatically. There is no separate step to register a skill in
`.agents/AGENTS.md.template`. For the full project workflow, see the
`drupal-skill-authoring` skill (`references/contributing-here.md`).

## Rebasing a branch that predates the Eval Commons model

#3588958 replaced the flat `evals.json` with `dataset.yaml` + `rubrics.yaml`.
If your branch still has an `evals.json`, CI fails with a layout error and the
behavioral cases would not run after merge. Convert it, one command per suite:

```bash
python3 evals/_migrate_to_commons.py --skill <name> --write
git rm evals/<name>/evals.json
```

The mapping is mechanical and preserves grading exactly (`prompt` becomes
`input`, `expected_output` becomes `criteria`, `must_contain_any` /
`must_not_contain` become rubric checks with `scoring.combine: all_pass`), and
the output is validated against the vendored schemas before it is written.

Needs `pip install pyyaml jsonschema`; the `--static` path is deliberately
pip-free, so a contributor who has only run static checks will not have them.

## Running evals locally

```bash
# Static only — no API calls, same checks as CI
python3 evals/run-evals.py --static

# One skill only
python3 evals/run-evals.py --static --skill drupal-gitlab

# Behavioral — requires a live AI CLI
python3 evals/run-evals.py --behavioral --skill drupal-gitlab
```

There is also a native **Inspect AI** runner under `evals/inspect/` (#3588916).
It reuses the same case format and `scoring.py`, run via `inspect eval` (the
behavioral install editable-installs `evals/inspect/`, registering the `abp_cli`
provider as an `inspect_ai` entry point):

```bash
inspect eval evals/inspect/tasks.py@abp -T skill=drupal-gitlab --model abp_cli/claude
# or, without the editable install:
python evals/inspect/run.py --skill drupal-gitlab --model claude
```

See `evals/inspect/README.md` for install and the CLI-wrapper rationale.

To publish a run (every skill with and without its skill, as a Hugging Face
dataset with a results table and Every Eval Ever records), use
`evals/inspect/publish_results.py`; see "Publish a results set" in
`evals/inspect/README.md`. Upload is a dry run unless `--yes`, and a new
dataset starts private.

Python 3.10+ required (the optional EEE export extra needs 3.12+ — see
`evals/requirements-eee.txt`). No pip deps for `--static`; behavioral mode (and the
Inspect runner) need extras — install with
`pip install -r evals/requirements-behavioral.txt` (PyYAML for YAML cases,
`inspect-ai` for the runner) plus a live AI CLI. Each skill's evals live in
`evals/<skill-name>/`:

- `static-checks.json` — structural assertions (CI-safe, no API calls, no pip deps)
- `dataset.yaml` + `rubrics.yaml` — behavioral cases in the Eval Commons dataset
  model (questions + per-case rubrics). Graded by `evals/scoring.py`, the shared
  scoring core used by both `run-evals.py` and `compare.py`.
- `README.md` — human-readable description of what the evals cover

## Corrections format

`corrections/<skill-name>.jsonl` — one JSON object per line capturing expert
corrections to AI mistakes. Key fields: `timestamp`, `subsystem`,
`classification` (`SKILL_GAP` | `SKILL_STALE` | `TOOL_MISUSE`), `claimed`,
`corrected`, `reasoning_failure`, `confidence`. Optional `eval_seed` for
turning a correction into a future behavioral eval.

## CI jobs

MR jobs run on merge-request events. The Pages catalog is the exception: it publishes on default-branch push.

| Job | Triggers when | What it runs |
|-----|--------------|--------------|
| `validate-agent-skills` | `skills/**/*` changed (MR) | `vendor/bin/agent-skills-validator` |
| `phpunit` | `src/**/*`, `tests/**/*`, composer files (MR) | PHPUnit |
| `evals-static` | `skills/**/*`, `evals/**/*`, catalog generator (MR) | `run-evals.py --static` (pip-free) then `pip install pyyaml` + catalog `--check` |
| `create-pages` | default-branch push | `build-agent-skills-index.py` → GitLab Pages (validates after write) |

## PHP plugin gotchas (`src/`)

**Invalid YAML for ai_best_practices.yaml:** `AiBestPracticesConfigLoader` returns 
defaults only when `ai_best_practices.yaml` is missing or semantically empty
(whitespace-only, parses to `null`, or `{}`). Unreadable, malformed, or
wrong-shape YAML throws `\InvalidArgumentException`; CLI sync/list and deferred
Composer sync abort rather than proceed on defaults. Do not reintroduce silent
fallback on the write path.

**Managed region merge:** `AgentsMdSync.php` merges package content into the
consumer's `AGENTS.md` without overwriting user edits. It does this via
delimited managed regions in the template. If you're modifying how agent files
are written, read `AgentsMdSync.php` before touching anything — the merge
strategy is non-obvious and easy to break silently.

**Path security:** all file copy operations must go through the `PathSecurity`
helpers (symlink detection, traversal prevention). Do not write your own path
validation — the helpers exist precisely because the naive approaches all have
edge cases.

## Git workflow on git.drupalcode.org

> **Source of truth:** the `drupal-gitlab` skill (`skills/drupal-gitlab/`) is the
> authoritative, tested guide for this workflow — issue forks, work items, MRs,
> CI, auth, and the gotchas. **Read it before doing GitLab work on this repo.**
> The summary below is a quick reference only; when the two disagree or the
> summary is silent, the skill wins.

**Use `glab` for all GitLab work** — issues, MRs, pipelines, and direct API calls. GitLab renders its UI in JavaScript; web fetchers return empty content. For any REST endpoint, use `glab api` — it handles auth automatically and covers all cases including multipart uploads via `--form` (e.g. `glab api --hostname git.drupalcode.org /version`). There is no remaining use case for `curl`.

**Commit format** (Conventional Commits, required):
```
{type}: #{issue-id} Short summary

Co-Authored-By: Name <email>
```
Types: `feat` `fix` `docs` `refactor` `test` `ci` `task`

**Issue fork workflow:** each MR lives on a separate issue fork.

*Creating a fork* (it cannot be created by pushing or via the GitLab fork API —
both are blocked). Provision it first, one of two ways:
```bash
# Option A — comment command (works headlessly via glab):
glab api --hostname git.drupalcode.org -X POST \
  /projects/<upstream-id>/issues/<issue-id>/notes -f "body=/do:fork"
# Option B — the "Create issue fork" button on the Drupal.org issue page.
```
Use `/do:access` (same notes endpoint) to get push access to a fork someone else
created. **Maintainers with push access to `project/<repo>` can skip the fork
entirely** and push the branch straight to `origin`.

*Working on an existing fork* (yours or another contributor's):
```bash
git remote add issue-N git@git.drupal.org:issue/ai_best_practices-{issue-id}.git
git fetch issue-N {branch-name}
git checkout -b {branch-name} issue-N/{branch-name}
# make changes, then:
git push issue-N {branch-name}
```
Opening the MR is a cross-project (fork→upstream) operation that `glab mr create`
**cannot** do — use the REST API with an explicit `target_project_id`. See
`skills/drupal-gitlab/references/merge-requests.md` for the exact call.

**Merges and pipeline triggers via the API are blocked** on git.drupalcode.org
at the infrastructure level. `glab mr merge` and `glab ci run` will not work.
All merges go through the GitLab web UI; pipelines fire on push events only.

**Linking MRs to issues:** put `Closes #N` in the MR description. GitLab wires
both sides automatically — the issue gets a "Development" widget showing the MR,
and the MR auto-closes the issue on merge. No manual linking step needed.

**Where to comment:**
- **Issue** — scope, design decisions, status updates, anything useful to
  someone reading the issue after the MR has merged
- **MR** — code review feedback, inline line comments, approval; anything only
  relevant while the change is in review
