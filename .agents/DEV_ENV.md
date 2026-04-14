# Local Development (DDEV)

## Quick start

```bash
ddev start
ddev composer install
ddev drush cr
ddev drush uli
```

## Config path

- Config sync dir: `config/default/`

## Drush essentials

```bash
ddev drush status
ddev drush cr
ddev drush cim -y
ddev drush cex -y
ddev drush updb -y
ddev drush config:status
```

## DB / logs

```bash
ddev mysql
ddev logs -f
ddev drush watchdog:show --count=50 --severity=Error
```

## Composer

```bash
ddev composer outdated 'drupal/*'
ddev composer require drupal/MODULE
ddev composer update drupal/MODULE --with-deps
```

## Env vars

- Store secrets in `.ddev/.env` (gitignored)
- Restart DDEV after changing env:

```bash
ddev restart
```
