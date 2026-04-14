# Drupal Update Reference

Detailed command options, troubleshooting guides, and advanced update procedures.

## Composer Commands

### composer update

```bash
ddev composer update [options] [packages]
```

**Common options:**

- `--with-all-dependencies`: Update dependencies of updated packages
- `--no-dev`: Skip development dependencies
- `--dry-run`: Simulate updates without making changes
- `--prefer-stable`: Prefer stable versions over dev versions
- `--optimize-autoloader`: Optimize autoloader for production

**Examples:**

```bash
# Update all packages within constraints
ddev composer update

# Update specific package with dependencies
ddev composer update drupal/webform --with-all-dependencies

# Dry run to see what would be updated
ddev composer update --dry-run

# Update everything except dev dependencies
ddev composer update --no-dev
```

### composer require

```bash
ddev composer require [options] [package:version]
```

**Version constraints:**

- `^3.0`: 3.0.0 or higher, but below 4.0.0
- `~3.6`: 3.6.0 or higher, but below 3.7.0
- `3.6.*`: Any 3.6.x version
- `>=3.6`: 3.6.0 or higher (not recommended)

**Examples:**

```bash
# Require major version update
ddev composer require drupal/anchor_link:^3.0

# Require specific minor version
ddev composer require drupal/webform:~6.2

# Update with all dependencies
ddev composer require drupal/admin_toolbar:^3.6 --update-with-all-dependencies
```

### composer outdated

```bash
ddev composer outdated [options] [packages]
```

**Options:**

- `--direct`: Only show direct dependencies
- `--format=json`: Output as JSON for parsing
- `--strict`: Return non-zero exit code if updates available
- `--minor-only`: Only show minor and patch updates

**Status codes:**

- `semver-safe-update`: Patch or minor within constraints
- `update-possible`: Major version update available
- `up-to-date`: No updates available

### composer audit

```bash
ddev composer audit [options]
```

**Options:**

- `--format=json`: Output as JSON
- `--locked`: Audit only locked packages
- `--no-dev`: Skip dev dependencies

**Example output:**

```
Found 2 security vulnerability advisories affecting 1 package:

Package: drupal/core
Version: 10.1.0
Advisory: SA-CORE-2023-001
CVE: CVE-2023-12345
Severity: High
```

## Drush Commands

### drush updb

```bash
ddev drush updb [options]
```

**Options:**

- `-y, --yes`: Auto-accept updates
- `--entity-updates`: Apply entity schema updates
- `--cache-clear`: Clear cache after updates
- `--no-cache-clear`: Skip cache clearing

**What it does:**

- Runs hook_update_N() implementations
- Applies entity schema changes
- Updates database schema
- Runs post-update hooks

### drush config:export

```bash
ddev drush config:export [options]
```

**Options:**

- `-y, --yes`: Auto-accept export
- `--diff`: Show configuration changes
- `--destination`: Export to custom directory

**Example:**

```bash
# Export with diff review
ddev drush config:export --diff

# Export to custom location
ddev drush config:export --destination=/tmp/config
```

### drush config:import

```bash
ddev drush config:import [options]
```

**Options:**

- `-y, --yes`: Auto-accept import
- `--partial`: Allow partial imports
- `--source`: Import from custom directory

### drush cache:rebuild

```bash
ddev drush cache:rebuild [options]
```

**Aliases:** `cr`, `rebuild`, `cache-rebuild`

Clears all cache bins including:
- Render cache
- Dynamic page cache
- Plugin cache
- Router cache
- Asset aggregation

## Troubleshooting

### Dependency Conflict Resolution

**Error:** "Your requirements could not be resolved"

**Steps:**

1. Read the conflict message carefully to identify the blocking package
2. Check if the blocking package has an update available:
   ```bash
   ddev composer outdated [blocking-package]
   ```
3. Try updating the blocking package first:
   ```bash
   ddev composer update [blocking-package] --with-all-dependencies
   ```
4. If still failing, check drupal.org issues for known conflicts

**Example conflict:**

```
Problem 1
  - drupal/webform 7.0.0 requires drupal/core ^10.2
  - Your drupal/core is locked to 10.1.5
  
Solution: Update core first
ddev composer update drupal/core-recommended --with-all-dependencies
```

### Memory Limit Issues

**Error:** "Allowed memory size exhausted"

**Solutions:**

1. Increase PHP memory limit in DDEV:
   ```bash
   ddev config --php-memory-limit=2G
   ddev restart
   ```

2. Or use composer's memory limit flag:
   ```bash
   COMPOSER_MEMORY_LIMIT=-1 ddev composer update
   ```

### Lock File Conflicts

**Error:** "composer.lock is out of sync with composer.json"

**Solution:**

```bash
# Update the lock file
ddev composer update --lock

# Or force a full update
ddev composer update
```

### Missing Dependencies

**Error:** "Package X is abandoned"

**Action:**

1. Check composer output for replacement package
2. If suggested, require the replacement:
   ```bash
   ddev composer require [replacement-package]
   ddev composer remove [abandoned-package]
   ```

### Database Update Failures

**Common issues:**

1. **Missing module**: Database update requires a module that's not installed
   - Solution: Install the module first, then run updb

2. **Timeout**: Long-running updates exceed time limit
   - Solution: Increase timeout in settings.php:
     ```php
     ini_set('max_execution_time', 300);
     ```

3. **Data integrity**: Update fails due to invalid data
   - Solution: Review error message, fix data manually, re-run

### Configuration Import Issues

**Error:** "Configuration sync directory is empty"

**Check:**

```bash
ddev drush status --field=config-sync
```

If directory doesn't exist or is empty:

```bash
# Export current config first
ddev drush config:export -y
```

**Error:** "Configuration import failed validation"

**Review:**

```bash
# Check what's different
ddev drush config:status

# Review specific config
ddev drush config:get [config.name]
```

## Advanced Procedures

### Updating Drupal Core

```bash
# Update to latest minor version
ddev composer update drupal/core-recommended --with-all-dependencies

# Update to next major version
ddev composer require drupal/core-recommended:^11 --update-with-all-dependencies

# Update core and all dependencies
ddev composer update drupal/core* --with-all-dependencies
```

### Handling Patches

If your project uses patches (via composer-patches):

1. Check if patches still apply after updates
2. Composer will fail if patches don't apply
3. Update or remove patches in composer.json as needed

**Example composer.json patches section:**

```json
"extra": {
  "patches": {
    "drupal/webform": {
      "Custom fix": "patches/webform-custom.patch"
    }
  }
}
```

### Downgrading a Package

If an update causes issues:

```bash
# Downgrade to specific version
ddev composer require drupal/[module]:[old-version] --update-with-all-dependencies

# Example
ddev composer require drupal/anchor_link:^2.7 --update-with-all-dependencies
```

### Checking Update History

```bash
# View recent composer operations
cat composer.lock | grep -A 5 "drupal/[module]"

# Check git history of composer files
git log --oneline composer.json composer.lock
```

## Best Practices

### Before Updates

1. **Backup database**: Use DDEV snapshot or manual backup
   ```bash
   ddev export-db --file=backup-$(date +%Y%m%d).sql.gz
   ```

2. **Check disk space**: Ensure adequate space for composer operations
   ```bash
   df -h
   ```

3. **Review release notes**: Check drupal.org for major changes

4. **Test on staging**: Always test updates on staging first

### During Updates

1. **Monitor composer output**: Watch for warnings and deprecated notices
2. **Keep logs**: Save composer output for documentation
   ```bash
   ddev composer update | tee update-log.txt
   ```
3. **One step at a time**: Don't combine too many updates

### After Updates

1. **Clear all caches**: Not just Drupal, also browser cache
2. **Check watchdog logs**: Look for new errors
   ```bash
   ddev drush watchdog:show --severity=Error --count=50
   ```
3. **Test functionality**: Run smoke tests on critical features
4. **Document changes**: Update changelog and team documentation

## Configuration Management Best Practices

### Export After Updates

Always export configuration after module updates:

```bash
# Export and review changes
ddev drush config:export --diff

# If changes look good, confirm export
ddev drush config:export -y
```

### Handle Configuration Conflicts

If updates create config conflicts:

1. Review the diff carefully
2. Use `config:import --partial` if needed
3. Consider using config split for environment-specific config

### Track Configuration Changes

```bash
# Before updates
git status config/sync/

# After updates
git diff config/sync/

# Commit configuration changes separately
git add config/sync/
git commit -m "Configuration updates from module upgrades"
```

## Performance Optimization

### Composer Performance

```bash
# Enable parallel downloads (Composer 2.2+)
ddev composer config --global repo.packagist composer https://repo.packagist.org

# Optimize autoloader
ddev composer dump-autoload --optimize

# Clear composer cache if issues
ddev composer clear-cache
```

### DDEV Performance

```bash
# Use mutagen for better performance (macOS)
ddev config --mutagen-enabled=true
ddev restart

# Increase resources if needed
ddev config --php-memory-limit=2G
ddev config --composer-version=2
```

## Security Considerations

### Reviewing Security Updates

Before auto-applying security updates:

1. Check the advisory on drupal.org
2. Review the severity level
3. Check if workarounds are available
4. Test on staging if possible

### Security Update Log

Always document security updates:

```markdown
## Security Update Log

Date: 2026-01-23
Vulnerability: SA-CORE-2026-001
Severity: Critical
Module: drupal/core
Action: Updated from 10.1.0 to 10.1.5
Reference: https://www.drupal.org/sa-core-2026-001
```

## Quick Commands Reference

```bash
# Environment
ddev describe                              # Check DDEV status
ddev start                                 # Start DDEV

# Safety
ddev snapshot --name=pre-update-$(date +%Y%m%d-%H%M%S)  # Create snapshot
ddev snapshot list                         # List snapshots
ddev snapshot restore --name=[name]        # Restore snapshot

# Updates
ddev composer audit                        # Check security
ddev composer update                       # Update all (safe versions)
ddev composer outdated 'drupal/*' --direct --format=json  # Check major updates
ddev composer require drupal/[module]:^[version]  # Major update

# Post-update
ddev drush updb -y                         # Database updates
ddev drush config:export -y                # Export configuration
ddev drush cache:rebuild                   # Clear caches

# Verification
ddev drush watchdog:show --count=20        # Check logs
ddev drush config:status                   # Check config status
```

## Resources

- [Drupal.org Security Advisories](https://www.drupal.org/security)
- [Composer Documentation](https://getcomposer.org/doc/)
- [Drush Documentation](https://www.drush.org/)
- [DDEV Documentation](https://ddev.readthedocs.io/)
