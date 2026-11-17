<!-- AI generated -->
# AGENTS.md — country

Guidance for AI coding agents working on this Drupal contrib module. Keep
edits minimal and consistent with the patterns below; everything here is
derived from the current code, README and the last year of commits on the
`2.2.x` branch.

## What this module does

Provides a simple `country` field type backed by Drupal core's
`CountryManager`. Ships five widgets (select, select with priorities,
autocomplete, radio/checkbox via core's `options_buttons`, Tagify when
enabled), two formatters (country name, ISO2 code), Views sort/filter
plugins, a Feeds target, a Facets processor, a Form API `#type =>
'country'` element, and token integration.

## Scope and non-goals

- Scope: a single-value or multi-value country code field with ISO 3166-1
  alpha-2 values, driven by core's country list.
- Non-goals:
  - Replacing or extending the core country list. Alter
    `country.field.manager` or override `CountryManager` yourself if you
    need that.
  - Managing full postal addresses. Use
    [Address](https://www.drupal.org/project/address) instead.
  - Rendering flag icons in content. Use the `flags_country` submodule of
    [Flags](https://www.drupal.org/project/flags).

## Architecture

The module is a thin layer on top of core field + `CountryManager`
services.

| Component | Responsibility |
|---|---|
| `CountryFieldManager` (service `country.field.manager`) | Resolves the selectable list for a field, honoring per-storage and per-instance `selectable_countries` settings; sorts via `\Collator` when `intl` is loaded. |
| `Element\Country` | `#type => 'country'` Form API element — autocomplete or select, with `#multiple`, `#default_value`, `#empty_option`. |
| `Plugin/Field/FieldType/CountryItem` | ISO2 field type. Validates against the selectable list. |
| `Plugin/Field/FieldWidget/CountryDefaultWidget` | Select widget. |
| `Plugin/Field/FieldWidget/CountryPrioritizedWidget` | Select widget that lists configured priority countries first, optionally in optgroups. |
| `Plugin/Field/FieldWidget/CountryAutocompleteWidget` | Autocomplete widget, powered by `CountryAutocompleteController`. |
| `Plugin/Field/FieldFormatter/CountryDefaultFormatter` | Displays the country name (localized). |
| `Plugin/Field/FieldFormatter/CountryCodeFormatter` | Displays the raw ISO2 code. |
| `Plugin/views/sort/CountryItem` + `Plugin/views/filter/CountryItem` | Views integration. |
| `Plugin/facets/processor/CountryName` | Facets processor that swaps ISO codes for country names in facet blocks. |
| `Feeds/Target/Country` | Feeds target for importing country values. |
| `Controller/CountryAutocompleteController` | Backs the `/country/autocomplete/{entity_type}/{bundle}/{field_name}` route. `_access: 'TRUE'` is intentional — it is an autocomplete endpoint, not a data leak. |
| `Hook/CountryHooks` | OOP hook class (new in `2.2.x`). Opts the field type into `options_buttons` and (if present) `tagify_select_widget`; registers the `country.country-icon` library; prefills the widget from `ip2country` when that module is enabled. |
| `Hook/CountryTokensHooks` | Token hooks. |
| `Hook/CountryViewsHooks` | Views hooks. |

### Patterns worth preserving

- **Hooks are OOP classes with `#[Hook(...)]` attributes.** The `f66d6d0`
  commit from the Project Update Bot migrated the legacy `country.module` /
  `country.tokens.inc` / `country.views.inc` to this layout. New hook
  implementations go into `src/Hook/`, not back into `country.module`.
- **Tagify widget support is conditional.**
  `CountryHooks::fieldWidgetInfoAlter` only registers the type on
  `tagify_select_widget` when the Tagify module is enabled (`da4639b`). Do
  not add Tagify as a hard dependency.
- **ip2country prefill is conditional.** Same pattern — only runs when
  `ip2country` is enabled. The autocomplete widget deliberately does not
  get this behavior; only the default (select) widget does.
- **The `_access: 'TRUE'` on the autocomplete route is intentional.** The
  inline comment calls it out. Do not "fix" this without understanding
  that it mirrors core's own autocomplete endpoint pattern.

## Branch and compatibility

- Active branch: **`2.2.x`**.
- `core_version_requirement: ^11.3 || ^12`. Previous `2.1.x` branch
  supported `^10 || ^11`; do not conflate the two.
- Drupal 12 compatibility was added in `7921db6`, briefly reverted in
  `2a68431`, and restored in `44ca0d8`. If a 12-compat change seems to
  break things, check which branch you're on.
- `.gitlab-ci.yml` enables `OPT_IN_TEST_PREVIOUS_MINOR: 1` and
  `OPT_IN_TEST_NEXT_MINOR: 1`; next-major is intentionally disabled
  (`bce1696`).

## Making changes

### Adding a new widget or formatter

- Put the plugin under `src/Plugin/Field/FieldWidget/` or
  `src/Plugin/Field/FieldFormatter/`.
- If it is a widget provided by another module (like Tagify or
  `options_buttons`), register it in
  `CountryHooks::fieldWidgetInfoAlter` conditionally on module presence,
  following the existing pattern.
- Add a Kernel test under `tests/src/Kernel/` mirroring
  `CountryWidgetTest` / `CountryFormatterTest`.

### Touching hook classes

- Keep hook methods `static` only where they already are. Methods that
  call `\Drupal::service(...)` can stay procedural-style; services are
  not injected into these legacy hook classes.
- Do not merge the three hook classes. `CountryHooks`,
  `CountryTokensHooks` and `CountryViewsHooks` are separate on purpose
  (and `CountryTokensHooks` even takes injected services via a
  non-autowire constructor).

### Views sort/filter plugins

- `sort/CountryItem` sorts by the localized country name, not the raw
  code — that's a deliberate UX choice. Preserve that unless an issue
  explicitly asks otherwise (see `1d9b3e6` for a related tweak under
  `#3596790`).

## AI-generated files

The following files carry an `AI generated` marker and should preserve
it on edit; new files you create should get the same marker in their
first comment block:

- `AGENTS.md`
- `mkdocs.yml`
- `project-page.html`

## Testing

Unit, Kernel and Functional tests all live under `tests/src/`. Kernel
tests share `CountryKernelTestBase`.

```bash
# From the project root
ddev phpunit web/modules/contrib/country
ddev phpstan web/modules/contrib/country
ddev phpcs   web/modules/contrib/country
```

Kernel/Functional tests need a database — see the project `CLAUDE.md`
for the one-time `web/core/phpunit.xml` setup.

CI runs via the standard Drupal `gitlab_templates` include
(`.gitlab-ci.yml`). ESLint is skipped. CSpell is active; add new unknown
technical terms to `_CSPELL_WORDS` there.

## Commit and issue conventions

- Substantive commits reference an issue:
  `Resolve #NNNNNNN "<short title>"` is the dominant pattern on `2.2.x`.
- Automated bot commits (Project Update Bot) land as
  `Automated Project Update Bot fixes from run <id>`; leave those alone
  unless you have a reason to rework them.
- CSpell-only commits are a thing (`Add tagify to cspell words`) — ship
  these alongside the feature commit they relate to.

## Recent history worth knowing (past ~12 months)

- **`#3596790`** — Drupal 12 compatibility: automated bot run, OOP hook
  migration, token hook rewrite, Views sort tweak.
- **`#3564737`** — Big test suite expansion (Kernel + Functional) and a
  Views filter fix.
- **Tagify widget support** — thin integration via
  `fieldWidgetInfoAlter`; added `tagify` to CSpell.
- **Drupal 12 compatibility churn** — added, reverted, then re-added.
  The current `2.2.x` state has it; `2.1.x` does not.
- **Next-major CI test disabled** (`bce1696`) — do not re-enable without
  checking that the pipeline still passes.

If you touch any of these areas, read the referenced commit or issue
before changing behavior.
