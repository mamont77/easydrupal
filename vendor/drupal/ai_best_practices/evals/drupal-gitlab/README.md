# Evals: drupal-gitlab

Tests for the `skills/drupal-gitlab/` skill. The skill uses **progressive
disclosure** (per the [Agent Skills spec](https://agentskills.io/specification)):
a lean `SKILL.md` entry point plus focused `references/*.md` files loaded on
demand (`contribution.md`, `issues.md`, `merge-requests.md`, `ci-cd.md`,
`commit-messages.md`, `migration.md`).

## Static assertions (no API calls, CI-safe)

```bash
python3 evals/run-evals.py --static --skill drupal-gitlab
```

37 checks. Content assertions use the per-check `"file"` field to target the
reference file that owns each convention:

- **SKILL.md (entry point):** frontmatter valid, host targeting via
  `--hostname git.drupalcode.org` (no `GITLAB_HOST`), the references map is
  present, Prerequisites before Cross-cutting gotchas, and the file stays lean
  (under 150 lines).
- **references exist:** `contribution.md`, `commit-messages.md`, `issues.md`,
  `merge-requests.md`, `ci-cd.md`, `migration.md` — each kept focused (under
  200 lines).
- **content landed in the right reference:** Conventional Commits + `By:`
  Drupal.org usernames (commit-messages), the issue-fork workflow + UI
  provisioning + cross-project MR gotcha (merge-requests), correct `--field`
  file-value handling and literal `--raw-field` handling (SKILL.md), `/-/work_items/`
  convention + `/do:fork` + `state::rtbc` (issues), `glab ci trace` (ci-cd),
  RTBC/reroll vocabulary translation (migration), and the end-to-end happy path
  + migrated-vs-legacy / fork-access decision tree (contribution: `drupalorg-cli`,
  `/do:access`, `Closes #`).

## Behavioral cases (requires an AI CLI)

```bash
python3 evals/run-evals.py --behavioral --skill drupal-gitlab
```

8 cases covering the conventions an agent most often gets wrong on
git.drupalcode.org:

- Listing issues with `--repo "git.drupalcode.org/project/<repo>"` (no `GITLAB_HOST`)
- Conventional Commits message with `By:` Drupal.org usernames (not `@user`)
- Cross-project MR via the REST API (not `glab mr create`)
- API merges are blocked; merge via the web UI, and `mergeable` does not
  imply fast-forwardable
- Issue fork must be provisioned via the Drupal.org UI before pushing (404 otherwise)
- Work item status is a GraphQL widget, not a label
- An empty `glab issue list` means the project is on the legacy queue, not a broken API
- A contributor facing someone else's existing fork needs `/do:access` (not `/do:fork`), then opens the MR via the REST API

Each case is written to discriminate skill-vs-baseline behaviour, so the set
also feeds the A/B comparison below. Expand it further as corrections
accumulate in `corrections/drupal-gitlab.jsonl`.

Note: the behavioral and A/B layers require a live AI CLI, so CI runs only the
static layer. Funding headless eval runs is tracked in #3588938.

## A/B comparison

```bash
python3 evals/compare.py --skill drupal-gitlab --no-baseline --runs 3
```

Reports pass rate delta, token usage, and cost per question. See
[CONTRIBUTING.md](../../CONTRIBUTING.md) for details.
