# Git & Delivery Workflow

## Branching

- `develop` -> active development
- `master` -> dev
- `staging` -> test/stage
- `feature/*`, `bugfix/*`, `hotfix/*`, `release/*`

## Commit format

`[type]: description` (<= 50 chars)

Types: `feat`, `fix`, `docs`, `style`, `refactor`, `perf`, `test`, `chore`, `config`

## Before PR checklist

Config sync dir: `config/default/`


```bash
ddev start
ddev composer install
ddev drush cr
ddev drush cex -y
# Quality (adjust to repo scripts if present)
ddev exec vendor/bin/phpcs --standard=Drupal web/modules/custom/ || true
ddev exec vendor/bin/phpstan analyze web/modules/custom --level=1 || true
ddev exec vendor/bin/phpunit web/modules/custom || true
```

## Release / hotfix

- Hotfix: `main -> hotfix/* -> main + staging`, tag release
- Never force-push shared branches.
