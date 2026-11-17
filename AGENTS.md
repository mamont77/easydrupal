# Project instructions for coding agents

## Environment
- Drupal 11.4 on PHP 8.3, running in DDEV (`drupal/legacy-project` layout: the web root is the repository root, there is no `web/` directory). Hosted on Pantheon. Run PHP, Composer, Drush and the checks inside DDEV (`ddev composer ...`, `ddev drush ...`, `ddev exec ...`).
- Custom modules live in `modules/custom/`. Exported site configuration lives in `config/`.

## Before writing code
- Check real field and bundle names in `config/field.field.*.yml` and `config/node.type.*.yml`. Never invent them.
- Scaffold modules, plugins and forms with `ddev drush generate` instead of writing boilerplate from memory.

## Drupal 11 coding rules
- Use PHP attributes, not docblock annotations: `#[Block]`, `#[Action]`, `#[Hook]` (hooks go in classes under `src/Hook/`), and PHPUnit attributes such as `#[Group]` in tests.
- Inject services through the constructor. Controllers and forms that extend `ControllerBase` or `FormBase`, and plugins that extend `PluginBase` and implement `ContainerFactoryPluginInterface`, autowire constructor arguments, so a hand-written `create()` is usually unnecessary. Never call `\Drupal::service()` or `\Drupal::entityTypeManager()` inside classes.
- In plugins, declare promoted constructor properties `protected`, not `private` or `readonly`.
- Call `->accessCheck(TRUE)` on entity queries, or `FALSE` with a documented reason.
- Add cache contexts, tags and max-age to render arrays whose output depends on the user, content or configuration.
- After changing configuration, export it with `ddev drush config:export` so it lands in `config/`.

## Checks to run before finishing
Code quality checks come from the DDEV add-on `mandclu/ddev-module-developer`. They mirror the Drupal GitLab CI jobs. Run them inside DDEV (if DDEV is not running, tell the user instead of starting it), and fix every reported error before presenting changes.

1. Run `ddev checks <path>` on the custom module or theme you changed, for example `ddev checks modules/custom/easydrupal_common`. It runs PHP Lint, PHP CodeSniffer (Drupal and DrupalPractice standards), PHPStan, ESLint, Stylelint and CSpell, then PHPUnit if the earlier checks pass.
2. If it fails, run `ddev checks-fixes <path>` first (it auto-fixes what it can), fix the remaining issues by hand, and re-run `ddev checks <path>` until it passes.
3. For deprecated API and Drupal 10 → 11 work, run `ddev rector <path>`. Show the proposed changes to the user before applying them.
4. Optional extra checks: `ddev checks --bonus <path>` adds PHP Mess Detector and PHP Compatibility. Single tools can be run on their own: `ddev phpcs`, `ddev phpstan`, `ddev phpunit`, `ddev eslint`, `ddev stylelint`, `ddev cspell`.

## Bug fixing
- For bugs in custom modules: first write a failing Kernel test that reproduces the bug, run it, confirm it fails. Only then fix.
- Test command: ddev exec "SIMPLETEST_DB=sqlite://localhost//tmp/test.sqlite vendor/bin/phpunit -c core <path>"

One-time local setup for `ddev checks` (git-ignored, so every developer does it once): `ddev exec "cd core && yarn install --frozen-lockfile"` and `ln -s core/node_modules node_modules`. ESLint needs these because the root `.eslintrc.json` extends core's config. PHPUnit is skipped by `ddev checks` in this project, because the add-on looks for `web/core` and this project has no `web/` directory.

Rules:
- Check only the code you changed. Never run checks on `core` or `modules/contrib`, and never "fix" code inside patched contrib modules.
- Do not run `ddev cspell --accept-words` without asking the user.
- In the final summary, report the `ddev checks` result (passed or failed, and which checks).

## Project overview
- Site "makedrupaleasy", hosted on Pantheon. Content types: `article`, `page`, `project`, `landingpage`, `feedback`, `client` (pathauto, metatag and rabbit_hole configured in `config/`).
- Custom code: `modules/custom/easydrupal_common` (site-wide hooks: front-page library stripping, media name from image alt, file/media delete cascade) and the Bootstrap 5 subtheme `themes/custom/easydrupal_b5`.
- Theme build (run in `themes/custom/easydrupal_b5/`): `npm install`; `gulp sass` (compile SCSS once), `gulp watch`, `gulp deploy` (production). SCSS lives in `assets/scss/`, compiled CSS in `dist/css/`.
- Do not modify `core/`. Contrib modules are managed by Composer; local patches live in `patches/` and are declared in `composer.json` under `extra.patches`.

<!-- ai-best-practices:start -->
<!-- Do not edit by hand inside the ai-best-practices markers in AGENTS.md; this block is regenerated when you update drupal/ai_best_practices. -->

## Drupal AI best practices

This project uses [`drupal/ai_best_practices`](https://www.drupal.org/project/ai_best_practices)
to provide AI guidance tailored for Drupal development.

**Skill discovery:** Skills are installed into `skills.output_directory` from
`ai_best_practices.yaml` (`.agents/skills/`, or `.claude/skills/` for projects
set up from Claude Code) when you run `composer install` or `composer update`.
AI clients that support the
[Agent Skills specification](https://agentskills.io/specification) load skills
automatically from that directory — no manual listing needed. For clients that
do not yet support automatic discovery, this file (`AGENTS.md`) acts as a
compatibility fallback; add explicit skill references here only if your tooling
requires it.

**What to commit:** Add the skills output directory and `AGENTS.md` to version control so all
team members and CI environments share the same AI context. Also commit
tool-specific files such as `CLAUDE.md` and `GEMINI.md` if your team uses those
clients.

<!-- ai-best-practices:end -->
