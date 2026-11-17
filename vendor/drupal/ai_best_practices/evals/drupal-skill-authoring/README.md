# Evals: drupal-skill-authoring

Tests for the `skills/drupal-skill-authoring/` skill — the meta-skill that gives
agents authoring context for writing, modifying, and reviewing Agent Skills. The
skill uses **progressive disclosure**: a lean `SKILL.md` plus focused
`references/*.md` (`frontmatter.md`, `progressive-disclosure.md`,
`contributing-here.md`).

## Static assertions (no API calls, CI-safe)

```bash
python3 evals/run-evals.py --static --skill drupal-skill-authoring
```

26 checks. Content assertions use the per-check `"file"` field to target the
reference that owns each convention:

- **SKILL.md (entry point):** frontmatter valid, the routing map is present, it
  names the 500-line guideline, states the validator checks frontmatter only,
  points to `docs/skill-authoring.md` for the decision framework, covers writing
  a *good* (not just valid) skill incl. evals-first, and stays lean (under 200
  lines).
- **references exist:** `frontmatter.md`, `progressive-disclosure.md`,
  `contributing-here.md` — each kept focused (under 200 lines).
- **content landed in the right reference:** the `name`-matches-directory rule +
  1024-char description limit + no-top-level-custom-keys warning + the
  metadata-is-spec-legal correction + reserved-words spec rule + third-person
  descriptions (frontmatter), the two canonical external spec URLs
  (progressive-disclosure), and the eval-trio requirement + auto-discovery note +
  validator command (contributing-here).

## Behavioral cases (requires an AI CLI)

```bash
python3 evals/run-evals.py --behavioral --skill drupal-skill-authoring
```

8 cases covering the authoring mistakes agents most often make:

- Using the canonical **500-line** guideline rather than inventing a smaller
  number (the empirical failure that motivated this skill).
- Recognizing that **top-level custom frontmatter keys** (`last-reviewed`,
  `drupal-version`) fail CI.
- The **`name` must match the directory** rule when renaming a skill.
- That the validator checks **frontmatter, not body length** — a 700-line body
  doesn't fail CI but still exceeds the spec guideline.
- The **four eval files** (`static-checks.json`, `dataset.yaml` + `rubrics.yaml`,
  `README.md`) and that skills **auto-discover** (no `AGENTS.md.template` wiring).
- Writing a **third-person description that triggers** and stays under 1024 chars.
- That nesting custom fields under **`metadata:` is spec-legal and passes CI**
  (a repo convention discourages it, but it is not forbidden) — guarding against
  the over-strict claim an earlier draft of this skill made.
- That a name containing the **reserved word `claude`** passes the repo validator
  but violates the spec (standard trumps local validator).

Note: the behavioral layer requires a live AI CLI, so CI runs only the static
layer.
