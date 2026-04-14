# Drupal Update Examples

Real-world scenarios demonstrating the update workflow.

## Example 1: Security Update + Routine Updates

**Scenario**: Regular maintenance with security patches available.

**Workflow**:

1. Create snapshot
2. Run composer audit → auto-apply security fixes
3. Run composer update → apply all safe updates
4. Check for major updates → none available
5. Run updb, export config, clear cache
6. Generate changelog

**Result**: All security and semver-safe updates applied automatically.

## Example 2: Major Version Update Needed

**Scenario**: A module has a major version update available.

**Workflow**:

1. Create snapshot
2. Run composer update → apply safe updates first
3. Check outdated → major update available
4. Ask user about major update
5. If confirmed: `composer require drupal/[module]:^[version]`
6. Run updb, export config, clear cache
7. Generate changelog with breaking changes noted

**Result**: Major version update applied after user confirmation.

## Example 3: Major Update Incompatible with Current Drupal Core

**Scenario**: Latest module version requires Drupal 11, but project runs Drupal 10.

**Workflow**:

1. Create snapshot
2. Run composer update → apply safe updates
3. Check outdated → major update available (e.g., 3.0.0)
4. Check compatibility → 3.0.0 requires Drupal 11, current is 10.3
5. Find highest compatible → 2.5.0 compatible with Drupal 10
6. Ask user about updating to 2.5.0 instead
7. If confirmed: `composer require drupal/[module]:^2.5`
8. Run updb, export config, clear cache
9. Generate changelog noting compatible version was used

**Result**: Highest compatible version installed without breaking Drupal core requirements.

**Key takeaway**: Always check compatibility to avoid installing versions that require newer Drupal core.

## Example 4: Update Fails

**Scenario**: Update encounters a dependency conflict or other error.

**Workflow**:

1. Note the error message
2. Do NOT proceed with database updates
3. Consider rolling back: `ddev snapshot restore`
4. Report issue to user with full context
5. Suggest checking drupal.org for known issues

**Result**: System remains stable, no broken state from partial updates.

**Recovery options**:
- Check if a conflicting module needs updating first
- Review the module's issue queue on drupal.org
- Try updating dependencies individually
- Wait for a compatible version to be released

## Example 5: Multiple Major Updates

**Scenario**: Several modules have major updates available.

**Workflow**:

1. Create snapshot
2. Apply all security and automatic updates first
3. Identify all major updates available
4. Check compatibility for each major update
5. Present list to user with compatibility information
6. User selects which major updates to apply
7. Apply major updates one at a time
8. After each major update:
   - Check for errors
   - If error occurs, note it and continue with remaining updates
9. Run updb, export config, clear cache
10. Generate changelog documenting all changes

**Result**: User has control over which breaking changes to accept.

**Best practice**: Apply major updates one at a time to isolate any issues.
