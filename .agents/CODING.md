# Coding Standards (Drupal 11)

## PHP

- `declare(strict_types=1);`
- DI everywhere (no `\Drupal::` inside services/controllers).
- Keep controllers thin; move logic into services.
- Use Drupal Database API (placeholders), Entity API, Cache API.
- Use typed properties, return types, and PHPDoc for complex types.

## Files & naming

- Custom modules: `web/modules/custom/{current_project}_*` (project) or `kwall_*` (shared)
- PSR-4: `src/` -> `\Drupal\{module}\...`
- Avoid magic numbers/strings; use enums/constants.

## JS / CSS

- ES6+
- Use Drupal behaviors + `once()` when attaching JS.
- Avoid global scope pollution.
- Prefer component-driven CSS (BEM/utility), keep selectors shallow.

## Drupal-specific rules

- Schema/config changes via update hooks in `.install`.
- Config changes must be exportable (`drush cex`) and reviewable.
- Respect cacheability metadata (`#cache` tags/contexts/max-age).
