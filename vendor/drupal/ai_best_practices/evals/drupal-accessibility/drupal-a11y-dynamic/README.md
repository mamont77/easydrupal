# Evals: drupal-a11y-dynamic

Tests for the `skills/drupal-accessibility/drupal-a11y-dynamic/SKILL.md` skill file.

## Static assertions (no API calls, CI-safe)

```bash
python3 evals/run-evals.py --static --skill drupal-a11y-dynamic
```

13 checks: file exists, frontmatter valid, key terms present
(Drupal.announce, aria-live prohibition, Drupal.dialog, FocusFirstCommand,
Playwright, @axe-core/playwright, issues #3553673 and #3338664,
colorScheme for dual-mode testing), in-flight caveat for AxeRenderArrayTrait,
line count under 290.

## Behavioral cases (requires claude CLI)

```bash
python3 evals/run-evals.py --behavioral --skill drupal-a11y-dynamic
```

5 tasks covering the main JS and testing rules:

1. `Drupal.announce()` for cart-add — no hand-rolled `aria-live`
2. AJAX focus return — `FocusFirstCommand`, not `element.focus()`
3. Playwright a11y test with `wcag22aa` tag and keyboard assertion
4. Dual colour-mode Playwright scan using `emulateMedia({ colorScheme })`
5. PHPUnit Kernel test with `AxeRenderArrayTrait` — including in-flight caveat

## Trace logging

```bash
python3 evals/run-evals.py --behavioral --skill drupal-a11y-dynamic --output-dir ./traces
```

## A/B comparison

```bash
python3 evals/compare.py --skill drupal-a11y-dynamic --runs 3
python3 evals/compare.py --skill drupal-a11y-dynamic --no-baseline --runs 3
```
