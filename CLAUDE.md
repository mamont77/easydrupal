# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a Drupal 11 project ("makedrupaleasy") using the `drupal/legacy-project` layout (web root is the repository root, not a `web/` subdirectory). It is hosted on Pantheon.

## Key Commands

### PHP / Drupal

```bash
# Install/update PHP dependencies
composer install
composer update

# Code style check (Drupal + DrupalPractice standards) for custom code only
composer code-sniff

# Auto-fix code style issues
composer code-fix

# Drush commands (run from repo root)
./vendor/bin/drush <command>

# Common Drush operations
./vendor/bin/drush cr          # Clear caches
./vendor/bin/drush updb        # Run database updates
./vendor/bin/drush cim         # Import configuration
./vendor/bin/drush cex         # Export configuration
./vendor/bin/drush en <module> # Enable module
```

### Theme (easydrupal_b5)

Run all commands from `themes/custom/easydrupal_b5/`:

```bash
npm install          # Install Node dependencies

# Compile SCSS to CSS (one-time)
gulp sass

# Watch SCSS for changes
gulp watch           # or just: gulp

# Production build
gulp deploy
```

SCSS source files live in `assets/scss/`; compiled CSS outputs to `dist/css/`.

## Architecture

### Directory Layout

- **`core/`** — Drupal core (do not modify)
- **`modules/contrib/`** — Contributed modules managed by Composer
- **`modules/custom/`** — Custom modules (`easydrupal_common`)
- **`themes/contrib/`** — Contributed themes (base: `bootstrap5`)
- **`themes/custom/easydrupal_b5/`** — Active custom theme (Bootstrap 5 subtheme)
- **`config/`** — Drupal configuration (exported YML files — the canonical config state)
- **`libraries/`** — JS/CSS libraries not managed by Composer
- **`sites/default/`** — Site settings, files
- **`private/`** — Private file storage
- **`patches/`** — Local patches applied via `cweagans/composer-patches`

### Custom Code

**`modules/custom/easydrupal_common`** — Site-wide behavioral hooks:
- Strips unnecessary libraries from anonymous front-page requests for performance
- Auto-sets media entity name from image alt text on presave
- Cascades file deletion to media (and vice versa) on entity delete

**`themes/custom/easydrupal_b5`** — Bootstrap 5 subtheme:
- Overrides `bootstrap5/global-styling` library entirely with its own
- Per-component SCSS files compiled to separate CSS libraries (e.g. `node-type-article`, `view-portfolio`, `landing-page`)
- FontAwesome loaded via CDN Kit (external JS)
- Slick carousel library included for project node type

### Content Types

`article`, `page`, `project`, `landingpage`, `feedback`, `client` — all with pathauto patterns, metatag fields, and rabbit_hole settings configured via exported config.

### Configuration Management

All site config is tracked in `config/` as YAML. Use `drush cim` to import and `drush cex` to export. The `drupal/config_partial_export` module is available for partial exports.

### Dependency Patching

Active patches are declared in `composer.json` under `extra.patches`. When adding a new patch, place the patch file in `patches/` (for local patches) or reference an upstream URL. The `cweagans/composer-patches` plugin applies them on `composer install`/`composer update`.
