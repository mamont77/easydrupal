# Evals: drupal-render-pipeline

Tests for the `skills/drupal-render-pipeline/SKILL.md` skill file.

## Static assertions (no API calls, CI-safe)

```bash
python3 evals/run-evals.py --static --skill drupal-render-pipeline
```

14 checks: file exists, frontmatter valid, key terms present
(render array, cache contexts, cache tags, #lazy_builder, #create_placeholder,
#plain_text, two-phase, CacheableMetadata, BigPipe, scalar arguments),
section ordering correct, line count under 250.

Note: this skill exceeds the ~150 line guideline. The expanded coverage
(CacheableMetadata, BigPipe, #access cacheability, renderInIsolation,
explicit-attachment cases) was added after a core philosophy lens review
and is tightly coupled to the render pipeline topic. See issue #3583217
for discussion.

## Behavioral cases (requires claude CLI)

```bash
python3 evals/run-evals.py --behavioral --skill drupal-render-pipeline
```

5 code generation tasks. Each asks the AI to generate actual Drupal code,
then checks:
- String assertions: correct return types, cache metadata present, wrong patterns absent
- PHP lint: generated code passes `php -l`

Cases cover: controller with cache metadata, block with cache tags,
lazy builder for personalized content, CacheableJsonResponse for API
endpoints, and #plain_text for user input.

## A/B comparison

```bash
python3 evals/compare.py --skill drupal-render-pipeline --no-baseline --runs 3
```

Reports pass rate delta, token usage, and cost per question. See
[CONTRIBUTING.md](../../CONTRIBUTING.md) for details.
