# Contributing to AI Best Practices for Drupal

Thank you for helping make Drupal development better for both humans and AI agents!
This project is a canonical, community-maintained collection of opinionated Drupal
best practice guidance. Before diving in, please read this document — it is itself
an example of the kind of clear, structured guidance we are trying to produce.

## How this project works

When a **Drupal site** installs this package via Composer, the plugin (after autoload dump) runs **`skills-sync`**, then writes or updates **`PROJECT_ROOT/AGENTS.md`** from [`.agents/AGENTS.md.template`](.agents/AGENTS.md.template): a full copy when **`AGENTS.md`** is missing, or only the HTML-comment **`ai-best-practices`** region on update so custom text outside the markers is preserved. It adds **`CLAUDE.md`** and **`GEMINI.md`** at the project root **only when those files are missing**; existing files are never overwritten (Composer warns to point them at **`AGENTS.md`**).

### Skills pipeline and discovery

Skills are aggregated into `skills.output_directory` (configurable in `ai_best_practices.yaml`; default follows the [agentskills#15](https://github.com/agentskills/agentskills/issues/15) layout—e.g. you might set `.claude/skills`). Projects configure sources in `ai_best_practices.yaml` (materialized from `ai_best_practices.yaml.dist` when missing):

- `discover_composer` / `discover_webroot` — scan `vendor/` and the Drupal document root (modules, themes, profiles, and other extensions under web-root). `discover_modules` is a deprecated alias for `discover_webroot`.
- `composer_package_whitelist` — optional `vendor/package` names; when non-empty, only those packages are scanned under `vendor/`. Empty means scan everything (default).

Repositories without a normal Composer release can still be pulled in via Composer’s `package` repository type; packages that expose skill directories participate when Composer discovery is enabled.

When `drupal/ai_best_practices` is installed or updated, the plugin schedules `skills-sync` (after autoload dump) unless skipped with `AI_BEST_PRACTICES_SKIP_PACKAGE_SYNC=1`. Consumers sometimes wire their own scripts, for example:

```json
{
  "scripts": {
    "post-install-cmd": ["@composer drupal-ai skills-sync"],
    "post-update-cmd": ["@composer drupal-ai skills-sync"]
  }
}
```

Relevant `drupal-ai` commands: `composer drupal-ai skills-sync` (sync into `output_directory`; `skills-install` is an alias), `composer drupal-ai skills-list`, and `composer drupal-ai skills-list --format=xml`. Agent UIs load bundled skills from `vendor/drupal/ai_best_practices/skills/` plus the aggregated output directory; the skill index in **`AGENTS.md`** is maintained in **`.agents/AGENTS.md.template`** (managed region).

### What to edit in this repository

Authoritative best-practice prose lives under `skills/` in this repo. The consumer skill index shipped in root **`AGENTS.md`** is defined **between** the **`ai-best-practices`** markers in [`.agents/AGENTS.md.template`](.agents/AGENTS.md.template); the Composer plugin copies or merges that region into the consumer project.

### PHP, YAML, style, and tests

The plugin code lives under `src/`. After changing `src/` or `tests/`, run `vendor/bin/phpunit`.

This repo targets PHP 8.1+ with Symfony Console / Filesystem / Yaml 6-8 (`composer.json`). After PHP edits, run `composer cs-fix` (PHP-CS-Fixer with `@Symfony`; see `.php-cs-fixer.dist.php`).

Redirect behavior for `CLAUDE.md` / `GEMINI.md` is covered by `tests/Agents/AgentRedirectFilesTest.php`; marker merge logic is covered by `tests/Agents/AgentsMdSyncTest.php`. Both use only temp directories under `sys_get_temp_dir()` so tests never write `AGENTS.md` / `CLAUDE.md` / `GEMINI.md` into this package root during CI or local runs.

### Smoke test (local checkout via path repository)

Use a disposable Composer project to verify that this repo installs from disk and that the plugin registers `drupal-ai`:

```bash
SMOKE=$(mktemp -d)
AI_BP_ROOT=/absolute/path/to/ai_best_practices   # your clone of this repository

cd "$SMOKE"
composer init --name=example/agents-smoke --type=project --no-interaction
composer config allow-plugins.drupal/ai_best_practices true
composer config repositories.ai_bp path "$AI_BP_ROOT"
composer require --dev 'drupal/ai_best_practices:@dev'

composer drupal-ai skills-list
test -f ai_best_practices.yaml && echo "config materialized"
grep -qx /ai_best_practices.yaml .gitignore && echo "config git-ignored"
```

## Writing or proposing skills

Before adding a new Agent Skill, read
[`docs/skill-authoring.md`](docs/skill-authoring.md).

In general:

- Follow the [Agent Skills specification](https://agentskills.io/specification).
- Use `name` and `description` as the only Drupal-wide required skill
  frontmatter fields.
- Use optional fields such as `license`, `compatibility`, and `metadata` only
  when they have a concrete purpose. Avoid `allowed-tools` unless the target
  agent/client supports it; the Agent Skills spec marks it experimental.
- Skills are procedural guidance, not parallel documentation.
- Use `AGENTS.md` for project-wide instructions that are always relevant; use
  skills for task-specific guidance that should load only when relevant.
- Prefer linking to canonical Drupal.org docs, API docs, change records, module
  docs, and local source over copying them.
- Put skills as close as possible to the project that owns the behavior.
- Only add skills to this project when they are cross-cutting, broadly useful,
  and have no better owner.

## Information architecture

### One skill = one directory

Each skill lives in its own directory with a required `SKILL.md` file.

Use `SKILL.md` for the concise procedural guidance the agent needs when the
skill activates. Use optional `references/`, `scripts/`, and `assets/`
directories only when progressive disclosure, executable helpers, or templates
are useful.

Do not copy large Drupal.org documentation pages into `SKILL.md`. Link to
canonical sources or place narrowly scoped supporting material in `references/`
when it is genuinely needed.

### Order options by what you want agents to choose first

When a skill file presents multiple approaches or types (e.g. test types, API
choices), list them in the order you want an agent to reach for them — not
alphabetically, not by conceptual complexity. Agents treat earlier entries as
higher-priority defaults. If the common failure mode is agents reaching for
the wrong option, put the right option first.

Example: `drupal-automated-testing/SKILL.md` lists Functional tests before Unit tests,
even though Unit tests are conceptually simpler, because the main failure mode
is agents defaulting to Unit when a Kernel or Functional test is more appropriate.

### Organise by task, not by role or subsystem

Skill files should be named and scoped around what someone (human or agent) is
**trying to do**, not around who they are or which Drupal API is involved.

Good: `drupal-automated-testing/SKILL.md`, `drupal-merge-requests/SKILL.md`, `drupal-security-response/SKILL.md`

Avoid: `developers.md`, `form-api.md`, `site-builders.md`

### File naming (directory name)

Each skill has a **name**: the directory name under `skills/` (kebab-case). The `name` field in
YAML frontmatter must match that directory name. Evals for that skill live under `evals/` in a
directory with the same name (for example `skills/drupal-automated-testing/` and
`evals/drupal-automated-testing/`) so consistency matters.

Pattern: **`<optional-domain>-<task-or-topic>`** in kebab-case.

- **Domain** (optional prefix): platform or product context when it helps scanning. In this
  repository, use the `drupal-` prefix for every skill because all guidance is Drupal-context.
- **Task or topic**: a short plain-English label for the work or subject the skill supports (a
  discipline, workflow, or concept). You are not required to use gerunds or any fixed grammar —
  pick whatever reads clearest in a list of skills.

When choosing a name, answer:

1. In what domain or context does this apply?
2. What task or subject does this help with?

Examples: `drupal-automated-testing`, `drupal-writing-documentation`, `drupal-config-management`,
`drupal-cache-debugging`.

Use **kebab-case** only (`drupal-automated-testing/SKILL.md`, not `snake_case` or `CamelCase`).

Upgrading this package can change skill paths under `vendor/`; update bookmarks or automation that
point at old paths.

### The skill index inside `.agents/AGENTS.md.template` is load-bearing

Every skill file must have a corresponding entry **between** the **`ai-best-practices`** markers in [`.agents/AGENTS.md.template`](.agents/AGENTS.md.template) (the plugin copies that inner region into the consumer’s **`PROJECT_ROOT/AGENTS.md`** on update). The entry must
include a "when to use" cue precise enough for an agent to decide whether to load
it for a given task. Vague one-liners cause agents to either miss relevant guidance
or load everything.

Good entry:
```
skills/drupal-automated-testing/SKILL.md
  When to use: You are writing or modifying any PHP code intended for contribution
  to Drupal core or a contrib module.
  Covers: PHPUnit, Nightwatch, test placement conventions, kernel vs. functional
  test selection, and the Drupal test runner.
```

Weak entry:
```
skills/drupal-automated-testing/SKILL.md - How to write tests.
```

## Skill file format

Every skill file must be a valid
[Agent Skill](https://agentskills.io/specification). At minimum, that means a
`SKILL.md` file with `name` and `description` frontmatter:

```markdown
---
name: drupal-example-skill
description: One sentence describing what this skill helps with and when to use it.
---

# [Task-oriented title]

## When to use this guidance
[One short paragraph: the situation in which an agent or human should consult
this file. Be specific enough to distinguish it from related skills.]

## Guidance

[The actual best practices. Use short paragraphs or numbered steps. Prefer
prose over bullet lists where possible — prose ages better and is easier for
agents to reason from. Reference authoritative sources rather than duplicating
them.]

## What not to do
[Optional but strongly encouraged: common mistakes to avoid.]

## See also
[Links to related skill files and canonical Drupal documentation.]
```

### Frontmatter fields

**`name`** must match the parent directory name and use the Agent Skills naming
rules.

**`description`** must describe both what the skill does and when an agent
should use it. Include task-specific keywords that help agents load the skill
only when relevant.

Do not require Drupal-specific frontmatter such as `drupal-version`, `status`,
`owner`, or `last-reviewed`. Lifecycle, ownership, freshness, and
package/version metadata should be handled by the owning project, issue or merge
request process, release/package metadata, registry, or future package-manager
conventions.

Use `compatibility` only when a skill has concrete runtime requirements, such as
required command-line tools, network access, a local Drupal checkout, or a
specific agent/client environment. Most skills do not need it.

Do not use `compatibility` as a broad substitute for Drupal version metadata.
When Drupal, module, or tool versions matter, the skill should instruct the
agent to inspect the local project, installed package versions, source code, and
canonical docs or change records.

Use `metadata` only for project-specific information that a client, registry,
validator, installer, or other tool will actually consume. It is not a
governance schema.

## Writing style

Write for both audiences simultaneously. Humans should find the guidance readable
and actionable. Agents should find it unambiguous and scannable.

- Use plain English. Avoid jargon that is not standard Drupal terminology.
- Be opinionated. "Prefer X over Y because Z" is more useful than "you might
  consider X or Y".
- Keep files short. If a skill file exceeds ~150 lines, consider splitting it.
- Prefer pointers to copies. Link to canonical Drupal documentation rather than
  reproducing it — copied content goes stale.
- Avoid code snippets unless they illustrate a non-obvious pattern. Agents can
  read the codebase; they benefit more from *why* than from *what*.

## Writing evals

Every skill file should have a matching eval directory at `evals/<skill-name>/`
(using the same kebab-case name as the skill file). Each eval directory contains
up to three files:

```
evals/
└── drupal-automated-testing/
    ├── README.md           # human-readable summary of what the evals cover
    ├── static-checks.json  # structural assertions (no API calls, CI-safe)
    ├── dataset.yaml        # behavioral cases (questions) in the Eval Commons model
    └── rubrics.yaml        # one rubric per case (the graded checks); needs a provider CLI
```

### static-checks.json

Structural assertions that run against the skill file itself — no AI, no API
calls, safe to run in CI. Use these to enforce that the skill file contains the
key terms and patterns it claims to teach.

```json
{
  "skill_file": "skills/drupal-automated-testing/SKILL.md",
  "assertions": [
    { "id": "S01", "description": "Skill file exists", "check": "file_exists", "path": "skills/drupal-automated-testing/SKILL.md" },
    { "id": "S02", "description": "Agent Skills frontmatter is valid", "check": "agent_skill_frontmatter" },
    { "id": "S03", "description": "Mentions key concept", "check": "contains", "text": "BrowserTestBase" },
    { "id": "S04", "description": "Correct concept ordering", "check": "order", "first": "**Functional tests**", "second": "**Unit tests**" },
    { "id": "S05", "description": "File not too long", "check": "max_lines", "limit": 155 }
  ]
}
```

Available check types: `file_exists`, `agent_skill_frontmatter`,
`frontmatter_fields`, `contains`, `order`, `max_lines`.

Prefer `agent_skill_frontmatter` for skill frontmatter checks. It verifies that
the required Agent Skills fields are present, that `name` matches the parent
directory, and that `description` is non-empty.

For skill quality, prefer content checks over lifecycle metadata checks. Useful
static assertions include:

- the skill has a clear "when to use" trigger;
- the skill points to canonical Drupal sources;
- the skill is procedural instead of a documentation mirror;
- the skill tells agents to inspect local project context where relevant;
- the skill avoids broad Drupal version assumptions;
- `SKILL.md` stays reasonably short and moves detail to `references/` when
  needed.

### dataset.yaml + rubrics.yaml

Behavioral cases follow the Eval Commons dataset model (the same schemas ai_eval
uses). `dataset.yaml` holds the questions; `rubrics.yaml` holds one rubric per
case with the graded checks. The runner injects the skill file as context, sends
each `input` to the AI, and grades the response against the case's rubric.
Requires PyYAML and a provider CLI (`claude` by default).

`dataset.yaml`:

```yaml
questions:
  - id: B01
    input: "Generate a Kernel test class for a module called my_module..."
    criteria: "Human-readable description of what a correct response looks like."
    bundle: drupal_automated_testing
    rubric_ref: rubric/drupal_automated_testing_b01@1.0
    metadata:
      expectations:
        - "Plain-English list of what the response should do — for human reviewers."
```

`rubrics.yaml`:

```yaml
rubrics:
  - id: drupal_automated_testing_b01
    version: "1.0"
    applicable_bundles: [drupal_automated_testing]
    checks:
      - kind: must_contain_any
        values: ["KernelTestBase"]
      - kind: must_not_contain
        values: ["extends UnitTestCase"]
      - kind: php_lint
    scoring:
      combine: all_pass
```

Keep `version` a quoted string. Unquoted YAML `1.10` loads as the float `1.1`,
and `load_cases()` keys rubrics as `f"{id}@{version}"`, so a `rubric_ref` ending
in `@1.10` would never resolve.

Check kinds (graded deterministically by `evals/scoring.py`):
- `must_contain_any` — response must include at least one `values` entry
- `must_not_contain` — response must include none of the `values`
- `php_lint` — all PHP code blocks in the response are run through `php -l`
- `markdown_structure` — deterministic structural checks on Markdown (see below)

`scoring.combine: all_pass` means every check must pass. `criteria` and
`metadata.expectations` are for human readers; they are not checked automatically.

#### `markdown_structure`

For skills that teach documentation or non-code writing, use a `markdown_structure`
check instead of `php_lint`. It parses the response as Markdown and checks
structural properties deterministically:

```yaml
- kind: markdown_structure
  min_headings: 3
  required_sections: ["prerequisite", "installation"]
  no_h1: true
  min_code_blocks: 1
  metadata:
    min_paragraphs: 8   # abp-specific rule; rides in the check's metadata slot
```

Base-contract rules:
- `min_headings` — minimum number of Markdown headings
- `max_heading_level` — deepest allowed heading level (e.g. `4` means `####` max)
- `no_h1` — `true` to forbid `#` headings (docs should use `##` or deeper)
- `required_sections` — heading texts that must appear (substring match, case-insensitive)
- `forbidden_patterns` — literal strings that must not appear anywhere
- `min_code_blocks` — minimum number of fenced code blocks

`min_paragraphs` (minimum non-empty, non-heading text lines) is an abp-specific
extension the Commons base schema does not model, so it goes in the check's
`metadata` slot rather than as a top-level field. The runner reads it from there.

### Running evals

```bash
# Static checks only (CI-safe)
python3 evals/run-evals.py --static

# Behavioral cases for one skill (defaults to the claude CLI)
python3 evals/run-evals.py --behavioral --skill drupal-automated-testing

# Run behavioral cases through a different provider
python3 evals/run-evals.py --behavioral --skill drupal-automated-testing --provider codex
python3 evals/run-evals.py --behavioral --skill drupal-automated-testing --provider gemini --model gemini-2.5-flash
python3 evals/run-evals.py --behavioral --skill drupal-automated-testing --provider mistral
python3 evals/run-evals.py --behavioral --skill drupal-automated-testing --provider kiro

# Save traces for offline review
python3 evals/run-evals.py --behavioral --skill drupal-automated-testing --output-dir ./traces

# Evaluate another repository's skills/ and evals/ with the runner from vendor/
python3 vendor/drupal/ai_best_practices/evals/run-evals.py --static --root .
```

`--root` points the runner at another repository laid out like this one,
with `skills/<name>/SKILL.md` and `evals/<name>/`. A package that ships its own
skills through the Composer sync can grade them with the runner it already
installs. The runner fails when the root has no eval suites, so a mistyped
path cannot pass by running nothing.

Supported providers are the same as for `compare.py` (see the "Supported
providers" table below). `run-evals.py` is a smoke test, so when
`--provider claude` is used without `--model`, the claude CLI's own default
model applies; for the other providers, the table defaults are used unless
`--model` is passed. `compare.py` always pins the claude default to `sonnet`
for reproducible A/B results across runs.

For non-claude providers, `run-evals.py` defaults the working directory to a
fresh tempdir so that the agent CLIs cannot mutate the repo. Pass
`--cwd /path/to/dir` to override.

### Native Inspect AI runner (alternative)

`run-evals.py` is the dependency-light smoke test. There is also a native
[Inspect AI](https://inspect.aisi.org.uk/) runner under `evals/inspect/`. It
reuses the same `dataset.yaml` / `rubrics.yaml` cases and the shared
`evals/scoring.py`, and adds Inspect's task, scorer, and log machinery. It needs
the behavioral extras (`pip install -r evals/requirements-behavioral.txt`):

```bash
# one skill (the abp_cli provider keeps the subscription billing posture)
inspect eval evals/inspect/tasks.py@abp -T skill=drupal-gitlab --model abp_cli/claude

# control arm: each case with and without the skill, paired delta + significance
python evals/inspect/run.py --skill drupal-gitlab --model claude --control
```

See `evals/inspect/README.md` for the provider rationale, the control arm, and
the Every Eval Ever Layer-2 export (`to_eee.py`).

### Comparing before / after (A/B testing)

`compare.py` measures the impact of your changes. It supports two workflows:

**Scenario 1: Editing an existing skill** (the common case)

You changed a skill file and want to know if your edit made things better or
worse. The script compares the old version against your working copy.

```bash
# Default: automatically uses the last committed version as baseline
python3 evals/compare.py --skill drupal-automated-testing --runs 3

# Or save the old version explicitly before editing
cp skills/drupal-automated-testing/SKILL.md /tmp/old-skill.md
# ... edit skills/drupal-automated-testing/SKILL.md ...
python3 evals/compare.py --skill drupal-automated-testing --baseline /tmp/old-skill.md --runs 3
```

**Scenario 2: Proving a new skill helps**

You wrote a new skill and want to show it adds value over no guidance at all.

```bash
python3 evals/compare.py --skill my-new-skill --no-baseline --runs 3
```

**Other options:**

```bash
# Test on a different model
python3 evals/compare.py --skill drupal-automated-testing --no-baseline --model haiku

# Compare multiple models to find which ones benefit from guidance
python3 evals/compare.py --skill drupal-automated-testing --no-baseline --models sonnet haiku --runs 3

# Run against a different AI provider
python3 evals/compare.py --skill drupal-automated-testing --no-baseline --provider codex --runs 3
python3 evals/compare.py --skill drupal-automated-testing --no-baseline --provider gemini --runs 3
python3 evals/compare.py --skill drupal-automated-testing --no-baseline --provider mistral --runs 3

# Dry run — see what would execute
python3 evals/compare.py --skill drupal-automated-testing --dry-run

# Save response traces for offline review
python3 evals/compare.py --skill drupal-automated-testing --runs 3 --output-dir ./traces
```

**Supported providers:**

| Provider | Default model | CLI required | Notes |
|----------|---------------|--------------|-------|
| `claude` (default) | `sonnet` | `claude` | Anthropic Claude Code CLI |
| `codex` | `gpt-5.4` | `codex` | OpenAI Codex CLI |
| `gemini` | `gemini-2.5-pro` | `gemini` | Google Gemini CLI |
| `mistral` | `codestral-latest` | none | Direct API via `CODESTRAL_API_KEY` or `MISTRAL_API_KEY` |
| `kiro` | `claude-sonnet-5` | `kiro-cli` 2.31+ | Kiro CLI, agent engine v3. Log in with `kiro-cli login`, or set `KIRO_API_KEY`. Kiro reports credits, not tokens, so no token or cost figures. `kiro-cli chat --list-models` lists the ids; avoid `auto`, which picks a model per task |

Pass a different model with `--model` per provider (e.g. `--provider gemini --model gemini-2.5-flash`). Provider selection is independent of the baseline/no-baseline workflow; you can run the same skill A/B test across providers to see which ones benefit most from guidance.

**How it works:**

1. Both configs invoke the same provider with identical flags. The only variable
   is which skill content is prepended to the prompt. The provider defaults to
   `claude` but can be switched with `--provider codex|gemini|mistral|kiro`.
2. The agent CLIs answer only and without your own setup. For `claude` the
   flags are `--no-session-persistence`, `--permission-mode auto`,
   `--output-format json`, `--setting-sources ""`, `--strict-mcp-config`,
   `--tools ""` and `--disable-slash-commands`: no user settings, no MCP
   servers, no tools and no skills, so your `~/.claude/CLAUDE.md` and personal
   skills stay out. `codex` runs in a throwaway home that holds only your
   login, read-only with its tools off and an empty skills catalog; the runner
   reads the context back from Codex's session log and stops with
   `ContextLeakError` if your AGENTS.md, memories, a skill or a path into your
   home shows up (see `evals/inspect/README.md`). `kiro` runs
   `kiro-cli chat --no-interactive --trust-tools=` with an empty throwaway
   `KIRO_HOME`: no tool is trusted, so it cannot run commands, read files or
   use the web, and your `~/.kiro` steering, skills and agents stay out. The
   runner stops with `ContextLeakError` if any steering, skill or agent that is
   not Kiro's own still shows up.
   `gemini` (`--approval-mode yolo`) is not isolated yet. Mistral is the
   direct HTTPS API.
3. Use `--cwd /tmp/some-neutral-dir` to prevent the model from seeing eval
   files, skill files, or project structure. The skill content is already
   injected into the prompt, so the working directory does not need to be
   the repo.
4. Grading is deterministic: `must_contain_any`, `must_not_contain`, `php -l`
   on generated code, and `check_markdown_structure` on documentation output.
   No LLM judge.
5. Results (including token counts and cost) are saved as JSON in the skill's
   eval directory.

**Reading the output:**

```
Case          old (git) new (working)      Delta
-------- -------------- -------------- ----------
B01        3/3 (100%)     3/3 (100%)          =     ← no change
B05        0/3 (0%)       3/3 (100%)      +100%     ← your edit fixed this
-------- -------------- -------------- ----------
TOTAL            80%           100%        +20%

  Avg output tokens          1,318          1,034       -284
  Avg cost/question $       0.0744 $       0.0643 $  -0.0101
```

A positive delta means your change improved things. Negative deltas on
individual cases mean your edit caused a regression on that case — investigate
the trace to understand why.

Use 3+ runs to distinguish real effects from noise. A case that passes 2/3
times is unreliable regardless of the skill version.

**Multi-model comparison:**

When you pass `--models`, the script runs each model separately and prints
per-model comparison tables followed by a cross-model summary:

```
CROSS-MODEL SUMMARY: drupal-automated-testing

Model       Baseline  Treatment    Delta   Avg In   Avg Out   Avg Cost
---------- ---------- ---------- -------- -------- --------- ----------
sonnet          80%       100%      +20%       3     1,412   $  0.0737
haiku           60%       100%      +40%      15     1,276   $  0.0445

  Skill helps:    sonnet, haiku
```

This answers the question "which models benefit from this guidance?" in a
single run. Use `--model` (singular) if you only want to test one model.

### Adding a new eval from scratch

When you write a new skill, create its eval alongside it:

1. Create the eval directory: `mkdir evals/my-new-skill/`

2. Write `evals/my-new-skill/static-checks.json` — structural assertions that
   verify the skill file has the right shape:
   ```json
   {
     "skill_file": "skills/my-new-skill/SKILL.md",
     "assertions": [
       { "id": "S01", "description": "Skill file exists", "check": "file_exists", "path": "skills/my-new-skill/SKILL.md" },
       { "id": "S02", "description": "Agent Skills frontmatter is valid", "check": "agent_skill_frontmatter" }
     ]
   }
   ```

3. Write `evals/my-new-skill/dataset.yaml` + `rubrics.yaml` — behavioral cases in
   the Eval Commons model. Each case should ask the model to **generate code**,
   not answer a knowledge question; code generation lets you grade with `php -l`
   and string assertions.
   ```yaml
   # dataset.yaml
   questions:
     - id: B01
       input: "Generate a Drupal service class that does X..."
       criteria: "What a correct response looks like (for human reviewers)."
       bundle: my_new_skill
       rubric_ref: rubric/my_new_skill_b01@1.0
       metadata:
         expectations: ["Uses constructor injection", "Implements an interface"]
   ```
   ```yaml
   # rubrics.yaml
   rubrics:
     - id: my_new_skill_b01
       version: "1.0"
       applicable_bundles: [my_new_skill]
       checks:
         - kind: must_contain_any
           values: ["__construct"]
         - kind: must_not_contain
           values: ["\\Drupal::service"]
         - kind: php_lint
       scoring:
         combine: all_pass
   ```

4. Write `evals/my-new-skill/README.md` — a brief summary of what the evals
   cover, following the pattern of `evals/drupal-automated-testing/README.md`.

5. Verify everything works:
   ```bash
   python3 evals/run-evals.py --static --skill my-new-skill
   python3 evals/compare.py --skill my-new-skill --no-baseline --dry-run
   ```

6. Run the comparison to prove the skill adds value:
   ```bash
   python3 evals/compare.py --skill my-new-skill --no-baseline --runs 3
   ```
   Include the comparison output in your merge request description.

## Proposing new guidance

1. **Search the issue queue first** at https://www.drupal.org/project/issues/ai_best_practices
   to avoid duplicating existing work.
2. **Open an issue** describing the gap in guidance. Include: the task it covers,
   why existing documentation does not address it well, and whether it applies to
   core, contrib, or both.
3. **Draft the skill file** following the Agent Skills format above.
4. **Update [.agents/AGENTS.md.template](.agents/AGENTS.md.template)** (between the **`ai-best-practices`** markers) with a precise "when to use" entry for your new file.
5. **Submit a merge request** on GitLab and link it to your issue.

Guidance that duplicates content already well-covered in official Drupal
documentation will generally be closed as out of scope. This project is for
guidance that is either missing from official docs or that benefits from being
presented in a condensed, agent-optimised format.

## Updating or contesting existing guidance

If you believe an existing skill file is wrong, outdated, or contested:

1. Open an issue explaining the concern and referencing any relevant Drupal change
   records, security advisories, or community discussion.
2. If the guidance is genuinely contested (reasonable people disagree), explain
   the disagreement in the skill body when the uncertainty changes what an agent
   should do.
3. If the guidance is simply outdated, update it and cite the current canonical
   sources.

Do not silently change opinionated guidance without opening an issue first. This
project is opinionated by design — changes should go through community discussion.

## Scope

This project covers **universal** Drupal contribution guidance: practices that apply
across Drupal core and contrib projects regardless of the specific site or use case.

**In scope:** coding standards, testing, security, accessibility, documentation,
issue etiquette, merge request conventions, deprecation handling.

**Out of scope:** site-specific configuration, hosting guidance, guidance that only
applies to a single contrib module, and content better suited to the official
[Drupal Contributor Guide](https://www.drupal.org/community/contributor-guide).

## Questions?

Open an issue or join the conversation in the
[#ai channel on Drupal Slack](https://www.drupal.org/community/contributor-guide/reference-information/talk/tools/slack).
