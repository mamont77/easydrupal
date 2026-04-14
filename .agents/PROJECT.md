# Project Context (Drupal 11)

## Stack

- Drupal: 11.x
- PHP: 8.3+
- Hosting: Pantheon (Composer build)
- Web root: `web/`
- Config: `config/default/` (export/import via Drush)
- Custom code:
  - Modules: `web/modules/custom/`
  - Themes:  `web/themes/custom/`

## Architecture

- Paragraphs-based landing pages
- SDC components where applicable
- Search API + Solr (Pantheon)
- External services:
  - Salesforce (JWT + mapping)
  - Mailgun

## AI in the project

- Provider: OpenAI (Drupal AI modules)
- Use cases: content generation, summarization, translation
- Secrets: `.ddev/.env` (gitignored)

## Non-negotiables

- Never commit credentials.
- No manual edits inside:
  - `vendor/`
  - `web/core/`
  - `web/modules/contrib/`
  - `web/themes/contrib/`
