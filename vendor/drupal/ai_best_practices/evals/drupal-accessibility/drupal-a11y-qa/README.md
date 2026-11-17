# Evals: drupal-a11y-qa

Tests for the `skills/drupal-accessibility/drupal-a11y-qa/SKILL.md` skill file
and the companion `REFERENCE_BUG_REPORT.md` template.

## Static assertions (no API calls, CI-safe)

```bash
python3 evals/run-evals.py --static --skill drupal-a11y-qa
```

12 checks: file exists, frontmatter valid, key terms present
(instance_id / pattern_id Bug IDs, SHA-256 hash, WCAG SC mapping,
one-issue-one-criterion rule, severity taxonomy, AT/browser pair,
AI disclosure block, RTBC manual-checks requirement, REFERENCE_BUG_REPORT.md
reference), line count under 200.

## Behavioral cases (requires claude CLI)

```bash
python3 evals/run-evals.py --behavioral --skill drupal-a11y-qa
```

5 tasks covering the main QA and issue-authoring rules:

1. Structured field block — Bug ID, URL, XPath, WCAG SC, rule, severity, screen type, colour mode
2. One-issue-per-criterion — two violations = two issues, linked as related
3. AI disclosure block — correct wording, tool name, reviewer, skills loaded
4. RTBC manual checks — keyboard, screen reader, 200% zoom, Axe alone insufficient
5. Bug ID format — SHA-256 hex hash, instance vs pattern inputs

## Trace logging

```bash
python3 evals/run-evals.py --behavioral --skill drupal-a11y-qa --output-dir ./traces
```

## A/B comparison

```bash
python3 evals/compare.py --skill drupal-a11y-qa --runs 3
python3 evals/compare.py --skill drupal-a11y-qa --no-baseline --runs 3
```
