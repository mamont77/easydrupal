# Evals: drupal-configuration

Tests for the `skills/drupal-configuration/SKILL.md` skill file.

## Static assertions (no API calls, CI-safe)

```bash
python3 evals/run-evals.py --static --skill drupal-configuration
```

22 checks: file exists, frontmatter valid, key terms present
(\Drupal::state(), settings.php, schema.yml, getEditable, drush cex,
ConfigEntityInterface, dependencies, third_party_settings, config actions,
#config_target, RedundantEditableConfigNamesTrait), section ordering correct,
line count under the 500-line guideline, and the Config Split reference
(`references/config-split.md`): it exists, SKILL.md points to it, the
Config Split row comes before the Settings row, and the reference covers
the settings.php switch, partial-split patches and the export trap in under
200 lines.

Note: this skill exceeds the ~150 line guideline. The expanded coverage
(config dependencies, third-party settings, config actions/recipes) was
added after a core philosophy lens review and is tightly coupled to the
configuration topic. See issue #3583219 for discussion.

## Behavioral cases (requires claude CLI)

```bash
python3 evals/run-evals.py --behavioral --skill drupal-configuration
```

6 code generation tasks. Each asks the AI to generate actual Drupal code
or make an architectural recommendation, then checks:
- String assertions: correct storage mechanism used, anti-patterns absent
- PHP lint: generated code passes `php -l`

Cases cover: API key storage (settings.php, not config), settings form
with schema and `#config_target`, State API for timestamps, config
overrides for environment-specific values, config entity vs. simple config
decision, and third-party settings for cross-module config.

## A/B comparison

```bash
python3 evals/compare.py --skill drupal-configuration --no-baseline --runs 3
```

Reports pass rate delta, token usage, and cost per question. See
[CONTRIBUTING.md](../../CONTRIBUTING.md) for details.
