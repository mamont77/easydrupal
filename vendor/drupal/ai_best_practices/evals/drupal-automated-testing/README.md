# Evals: drupal-automated-testing

Tests for the `skills/drupal-automated-testing/SKILL.md` skill file.

## Static assertions (no API calls, CI-safe)

```bash
python3 evals/run-evals.py --static --skill drupal-automated-testing
```

8 checks: file exists, frontmatter valid, key terms present
(BrowserTestBase, KernelTestBase, waitForElement, RunTestsInSeparateProcesses),
Functional listed before Unit in the hierarchy, line count under 155.

## Behavioral cases (requires claude CLI)

```bash
python3 evals/run-evals.py --behavioral --skill drupal-automated-testing
```

5 code generation tasks. Each asks the AI to generate actual Drupal test
classes, then checks:
- String assertions: correct base class used, wrong base classes absent
- PHP lint: generated code passes `php -l`

Cases cover: Kernel test generation, Functional test generation,
FunctionalJavascript with waitForElement, Kernel setUp with installEntitySchema,
and RunTestsInSeparateProcesses attribute inclusion.

## Trace logging

```bash
python3 evals/run-evals.py --behavioral --skill drupal-automated-testing --output-dir ./traces
```

Saves raw prompt + response + assertion results as JSON for offline review.

## A/B comparison

```bash
# Did your edit improve the skill? Compares last committed version vs working copy.
python3 evals/compare.py --skill drupal-automated-testing --runs 3

# Does the skill help at all? Compares no-skill vs with-skill.
python3 evals/compare.py --skill drupal-automated-testing --no-baseline --runs 3
```

Reports pass rate delta, token usage, and cost per question. See
[CONTRIBUTING.md](../../CONTRIBUTING.md) for details.
