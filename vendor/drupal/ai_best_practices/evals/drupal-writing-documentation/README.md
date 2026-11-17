# Evals: drupal-writing-documentation

Tests for the `skills/drupal-writing-documentation/SKILL.md` skill file.

## Static assertions (no API calls, CI-safe)

```bash
python3 evals/run-evals.py --static --skill drupal-writing-documentation
```

12 checks: file exists, frontmatter valid, key principles present
(link-don't-copy, audience awareness, opinionated docs, Drupal terminology,
scannability), references Content Style Guide, correct section ordering,
line count under 200.

## Behavioral cases (requires claude CLI)

```bash
python3 evals/run-evals.py --behavioral --skill drupal-writing-documentation
```

5 documentation tasks. Each asks the AI to write or improve Drupal
documentation, then checks:
- String assertions: required sections present, anti-patterns absent
- Markdown structure: heading hierarchy, code blocks, paragraph count

Cases cover: writing a handbook installation guide (B01), identifying the
correct documentation silo for an API fix (B02), rewriting bad documentation
to fix anti-patterns (B03), advising against exhaustive feature documentation
(B04), and writing a contrib module README (B05).

### New assertion type: `check_markdown_structure`

These evals introduce a new deterministic assertion type for grading
non-code output. Instead of `check_php_lint`, documentation evals use
`check_markdown_structure` to verify structural quality:

```json
"check_markdown_structure": {
  "min_headings": 3,
  "required_sections": ["prerequisite"],
  "no_h1": true,
  "min_code_blocks": 1,
  "min_paragraphs": 8
}
```

Supported rules: `min_headings`, `max_heading_level`, `required_sections`,
`forbidden_patterns`, `min_code_blocks`, `min_paragraphs`, `no_h1`.

## Trace logging

```bash
python3 evals/run-evals.py --behavioral --skill drupal-writing-documentation --output-dir ./traces
```

Saves raw prompt + response + assertion results as JSON for offline review.

## A/B comparison

```bash
# Did your edit improve the skill?
python3 evals/compare.py --skill drupal-writing-documentation --runs 3

# Does the skill help at all?
python3 evals/compare.py --skill drupal-writing-documentation --no-baseline --runs 3
```

Reports pass rate delta, token usage, and cost per question. See
[CONTRIBUTING.md](../../CONTRIBUTING.md) for details.
