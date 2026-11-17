# Evals: drupal-a11y-fapi

Tests for the `skills/drupal-accessibility/drupal-a11y-fapi/SKILL.md` skill file.

## Static assertions (no API calls, CI-safe)

```bash
python3 evals/run-evals.py --static --skill drupal-a11y-fapi
```

11 checks: file exists, frontmatter valid, key terms present
(#title, #description, fieldset, #markup, #alt, #type, #title_display),
prohibition on redundant ARIA roles, line count under 230.

## Behavioral cases (requires claude CLI)

```bash
python3 evals/run-evals.py --behavioral --skill drupal-a11y-fapi
```

5 code generation tasks covering the main FAPI accessibility rules:

1. Email field with `#title`, `#description`, `#required` — no hand-rolled `aria-*`
2. Radio group in a `fieldset` or `details` container — no bare `<div>` grouping
3. Call-to-action link via `#type => 'link'` — not a `#markup` HTML string
4. Image render elements: descriptive `#alt` vs `#alt => ''` for decorative
5. Data table via `#type => 'table'` with `#caption` and `#empty`

Each case checks string assertions and runs `php -l` on generated PHP.

## Trace logging

```bash
python3 evals/run-evals.py --behavioral --skill drupal-a11y-fapi --output-dir ./traces
```

## A/B comparison

```bash
python3 evals/compare.py --skill drupal-a11y-fapi --runs 3
python3 evals/compare.py --skill drupal-a11y-fapi --no-baseline --runs 3
```
