# Evals: drupal-a11y-dom

Tests for the `skills/drupal-accessibility/drupal-a11y-dom/SKILL.md` skill file.

## Static assertions (no API calls, CI-safe)

```bash
python3 evals/run-evals.py --static --skill drupal-a11y-dom
```

12 checks: file exists, frontmatter valid, key terms present
(.visually-hidden, aria-hidden, 24px target size, :focus-visible,
prefers-color-scheme, forced-colors, prefers-reduced-motion, js-show),
prohibition on redundant ARIA roles, line count under 325.

## Behavioral cases (requires claude CLI)

```bash
python3 evals/run-evals.py --behavioral --skill drupal-a11y-dom
```

5 tasks covering the main DOM/CSS accessibility rules:

1. Main nav Twig template — `<nav>` without redundant `role="navigation"`
2. Skip-link hiding — `.visually-hidden`, not `display:none`
3. Icon button CSS — 24×24 px hit area via padding, not icon enlargement
4. Animated component — gated inside `prefers-reduced-motion`
5. Light/dark theme CSS — custom property tokens + `prefers-color-scheme: dark`

## Trace logging

```bash
python3 evals/run-evals.py --behavioral --skill drupal-a11y-dom --output-dir ./traces
```

## A/B comparison

```bash
python3 evals/compare.py --skill drupal-a11y-dom --runs 3
python3 evals/compare.py --skill drupal-a11y-dom --no-baseline --runs 3
```
