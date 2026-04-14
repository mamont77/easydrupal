# Drupal Module Update Skill

Automates the Drupal module update workflow in DDEV environments with safety snapshots, automatic updates, and changelog generation.

## Installation

No installation required! The skill uses PHP (already available in DDEV) to fetch changelogs.

The changelog fetcher script runs with `ddev exec php` and has no external dependencies.

## Usage

This skill is automatically triggered when you ask the agent to:
- Update Drupal modules
- Check for updates
- Upgrade dependencies
- Run composer update

## Features

- **Safety First**: Automatic DDEV snapshots before updates
- **Security Updates**: Auto-applied without confirmation
- **Smart Updates**: Automatic `composer update` for safe versions
- **Interactive Major Updates**: Asks before applying breaking changes
- **Post-Update Tasks**: Database updates, config export, cache clear
- **Changelog Generation**: Automatic changelog with release notes

## Workflow

1. Create DDEV snapshot
2. Apply security updates automatically
3. Run `composer update` for all safe updates
4. Check for major version updates
5. Ask user confirmation for major updates
6. Apply confirmed major updates with `composer require`
7. Run database updates
8. Export configuration
9. Clear caches
10. Generate changelog

## Files

- `SKILL.md` - Main skill instructions for the agent
- `reference.md` - Detailed command reference and troubleshooting
- `examples.md` - Real-world update scenarios and workflows
- `scripts/fetch_changelog.php` - PHP script to fetch release notes from drupal.org

## Testing

Test the changelog script (replace `[skill-directory]` with the actual path where this skill is installed):

```bash
# From project root
ddev exec php [skill-directory]/scripts/fetch_changelog.php drupal/admin_toolbar 3.6.2 3.6.3

# Try different formats
ddev exec php [skill-directory]/scripts/fetch_changelog.php drupal/webform 6.2.0 6.2.9 --format=json
```

## Rollback

All updates create a DDEV snapshot. To rollback:

```bash
# List snapshots
ddev snapshot list

# Restore to previous state
ddev snapshot restore --name=pre-update-[timestamp]
```

## Support

- For real-world examples, see [examples.md](examples.md)
- For detailed commands, see [reference.md](reference.md)
- For Drupal documentation, visit [drupal.org](https://www.drupal.org)
- For DDEV documentation, visit [ddev.readthedocs.io](https://ddev.readthedocs.io/)
