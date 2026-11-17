# Evals: drupal-ckeditor5-plugins

Tests for the `skills/drupal-ckeditor5-plugins/` skill — CKEditor 5 plugin
customization in Drupal: version alignment, the DLL vs. new-builds
installation split, the Plugin Pack / Premium Features modules, and CKEditor's
agent-friendly docs.

## Static assertions (no API calls, CI-safe)

20 checks. Content assertions use the per-check `"file"` field to target the
reference that owns each convention:

- **SKILL.md (entry point):** frontmatter valid, the Step 0 version check
  reads `core/core.libraries.yml` and comes before the routing table, warns
  against `ckeditor.com/docs/ckeditor5/latest/`, stays under 150 lines.
- **references exist and stay focused:** `version-matrix.md` (pins docs URLs
  to the bundled version), `custom-plugins-dll.md` (teaches
  `DllReferencePlugin`), `drupal-12-outlook.md` (flagged beta),
  `plugin-pack-premium-features.md` (names both modules; the check-first
  ladder — existing plugins and their issue queues before custom code —
  appears before the module details), `agent-docs.md` (covers
  `llms-full.txt`).

## Behavioral cases (requires an AI CLI)

Six cases covering the decisions an agent most often gets wrong:

- **B01** — webpack config for a custom plugin on Drupal 11 must use the DLL
  pattern (UMD + `DllReferencePlugin`), not a standalone npm build.
- **B02** — docs lookups for Drupal 10.6 are pinned to the bundled CKEditor
  version via `core/core.libraries.yml`, not `latest`.
- **B03** — Drupal 12 projects take the 2.x module line (CKEditor 5 v48+);
  the DLL bundler config does not carry over.
- **B04** — knows CKEditor's machine-friendly docs: `.md` URLs /
  `Accept: text/markdown`, `llms-full.txt`, the docs MCP server.
- **B05** — generates a valid `CKEditor5PluginDefault` PHP class plus
  `.ckeditor5.yml` wiring (graded with `php_lint`).
- **B06** — asked to hand-build an emoji plugin, recommends the existing
  Plugin Pack plugin (check-first rule) instead of jumping to custom code.

Run:

```bash
python3 evals/run-evals.py --static --skill drupal-ckeditor5-plugins
python3 evals/run-evals.py --behavioral --skill drupal-ckeditor5-plugins
python3 evals/compare.py --skill drupal-ckeditor5-plugins --no-baseline --runs 3
```
