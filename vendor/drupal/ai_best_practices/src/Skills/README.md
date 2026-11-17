# Skills aggregation

Default `output_directory` follows [agentskills#15](https://github.com/agentskills/agentskills/issues/15). When `ai_best_practices.yaml` is first created from Claude Code (the `CLAUDECODE` environment variable is set, or the project has a `.claude/` directory), it is set to `.claude/skills` instead, because Claude Code loads project skills only from there. Change `output_directory` in `ai_best_practices.yaml` at any time.

Configure skill sources in **`ai_best_practices.yaml`** (materialized from `ai_best_practices.yaml.dist` when missing):

- **discover_composer** / **discover_webroot** — scan `vendor/` and the Drupal document root (modules, themes, profiles, and other extensions under web-root). `discover_modules` is a deprecated alias for `discover_webroot`.
- **web_root** — optional override for the document root prefix (e.g. `html/`). Auto-detected from `composer.json` when unset.
- **composer_package_whitelist** — optional list of `vendor/package` names; when non-empty, only those Composer packages are scanned under `vendor/`. Empty means all packages (default).

For Git repositories that do not have a `composer.json` or tagged versions, you can use Composer's `package` repository type to add them to your `composer.json`. Installed packages with skill directories are discovered when Composer discovery is enabled.

When **`drupal/ai_best_practices`** is installed or updated via Composer, the plugin schedules **`skills-sync`** (after autoload generation) unless you skip it with **`AI_BEST_PRACTICES_SKIP_PACKAGE_SYNC=1`**.

You can also add your own Composer scripts—for example:

```json
{
  "scripts": {
    "post-install-cmd": ["@composer drupal-ai skills-sync"],
    "post-update-cmd": ["@composer drupal-ai skills-sync"]
  }
}
```

Commands (via root script `drupal-ai`):

- `composer drupal-ai skills-sync` — Sync skills from configured sources into `output_directory` (alias: `skills-install`).
- `composer drupal-ai skills-list` — List skills with validation status.
- `composer drupal-ai skills-list --format=xml` — XML for tooling.
