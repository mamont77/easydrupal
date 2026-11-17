<?php

namespace Drupal\Tests\upgrade_status\Functional;

/**
 * Has the checks that the commands pass in Drush and in vendor/bin/dr.
 *
 * Each way to run the commands has its own test class, so they can run at
 * the same time.
 */
abstract class UpgradeStatusCommandsTestBase extends UpgradeStatusTestBase {

  /**
   * Checks the site report with either command.
   *
   * @param callable $run
   *   Runs the report command with an array of options. Returns the output.
   */
  protected function assertReport(callable $run): void {
    $output = $run([]);
    $this->assertStringContainsString('# Upgrade Status report for Drupal ' . ($this->getDrupalCoreMajorVersion() + 1), $output);
    $this->assertStringContainsString("## Report accuracy\n", $output);
    $this->assertLessThan(strpos($output, "## Summary\n"), strpos($output, "## Report accuracy\n"));
    $this->assertStringContainsString("## Summary\n", $output);
    $this->assertStringContainsString('% done, counting the environment as one project.', $output);
    $this->assertStringContainsString('## Drupal core and hosting environment', $output);
    // How to meet a requirement is only shown if it is not met.
    if (str_contains($output, '- Passed: PHP version should be at least')) {
      $this->assertStringNotContainsString('composer why-not php', $output);
    }
    $this->assertStringContainsString('- Upgrade status test error (upgrade_status_test_error): Custom module, Installed. Local version: ', $output);
    $this->assertStringContainsString('(upgrade_status_test_contrib_error): Contributed module, Installed.', $output);
    // The groups have the descriptions of the report page, with the commands
    // to run added.
    $this->assertStringContainsString("## Fix manually\n\nFix the problems in the code of these custom projects. See the problems with `upgrade-status:analyze --skip-existing` and the project name.\n", $output);
    // How to check a fix is in the summary once, not in every group.
    $check = 'After a fix, run `upgrade-status:analyze` with the project name, without `--skip-existing`. Exit code 0 means no problems are left for Drupal ';
    $this->assertStringContainsString("## Summary\n\nThe groups below are in the order to work on them. $check", $output);
    $this->assertSame(1, substr_count($output, 'After a fix, run'));

    // Only the environment.
    $output = $run(['groups' => 'environment']);
    $this->assertStringContainsString('## Drupal core and hosting environment', $output);
    $this->assertStringNotContainsString('(upgrade_status_test_error)', $output);

    // Only the compatible projects.
    $output = $run(['groups' => 'compatible']);
    $this->assertStringNotContainsString('## Drupal core and hosting environment', $output);
    $this->assertStringContainsString('## Nothing to do', $output);
    $this->assertStringNotContainsString('(upgrade_status_test_error)', $output);

    // Without custom projects.
    $output = $run(['ignore-custom' => TRUE]);
    $this->assertStringNotContainsString('(upgrade_status_test_error)', $output);
    $this->assertStringContainsString('(upgrade_status_test_contrib_error)', $output);

    // The report as an HTML document.
    $output = $run(['format' => 'html', 'groups' => 'fix-manually']);
    $this->assertStringContainsString('<h1>Upgrade Status report for Drupal ', $output);
    $this->assertMatchesRegularExpression('@<details[^>]+id="edit-manual"@', $output);
    $this->assertStringContainsString('<span>Fix manually</span>', $output);
    $this->assertStringContainsString('Upgrade status test error (upgrade_status_test_error)', $output);
    $this->assertStringNotContainsString('id="edit-environment"', $output);
    $this->assertStringNotContainsString('<input', $output);

    // The projects as CSV, without the summary.
    $output = $run(['format' => 'csv', 'groups' => 'environment,fix-manually']);
    $this->assertStringStartsWith('Group,Project,"Machine name",', $output);
    $this->assertStringNotContainsString('Summary', $output);
    $this->assertStringContainsString("\n\"Fix manually\",\"Upgrade status test error\",upgrade_status_test_error,\"Custom module\",Installed,", $output);
    $this->assertStringNotContainsString('upgrade_status_test_13_compatible', $output);
  }

  /**
   * Runs the same checks with either command.
   *
   * @param callable $run
   *   Runs the command with a command name, a list of projects, an array of
   *   options and the expected exit code. Returns the output.
   */
  protected function assertCommand(callable $run): void {
    // Test a module that needs an info file change, with the short alias.
    $output = $run('us-a', [$this->getModuleWithoutNextMajorSupport()], [], 3);
    $this->assertStringContainsString('# Upgrade Status results for Drupal ', $output);
    $this->assertStringContainsString('(' . $this->getModuleWithoutNextMajorSupport() . ')', $output);
    $this->assertStringContainsString('### Update requirements', $output);
    $this->assertMatchesRegularExpression('/^  - (Line \\d+: )?Value of core_version_requirement:/m', $output);

    // Test a Drupal 13 compatible module, with the old name of the command,
    // which Drush still accepts.
    $output = $run('upgrade_status:analyze', ['upgrade_status_test_13_compatible'], [], 0);
    $this->assertStringContainsString('No known issues found.', $output);

    // Test checkstyle output.
    $output = $run('upgrade-status:analyze', ['upgrade_status_test_error'], ['format' => 'checkstyle'], 3);
    $this->assertStringContainsString('<checkstyle', $output);
    $this->assertStringContainsString('<file', $output);
    $this->assertStringContainsString('<error', $output);

    // The other formats use the results of that analysis. Analyzing the same
    // project for each format takes most of the time of this test.
    $existing = ['skip-existing' => TRUE];

    // Test CSV output, with one row per message after the header.
    $output = $run('upgrade-status:analyze', ['upgrade_status_test_error'], ['format' => 'csv'] + $existing, 3);
    $this->assertStringStartsWith('Group,Project,"Machine name",', $output);
    $this->assertStringContainsString(',"Fix manually","Upgrade status test error",upgrade_status_test_error,"Custom module",', str_replace("\n", ',', $output));
    $this->assertStringContainsString('UpgradeStatusTestErrorController.php', $output);

    // Test HTML output, like the HTML export on the report page.
    $output = $run('upgrade-status:analyze', ['upgrade_status_test_error'], ['format' => 'html'] + $existing, 3);
    $this->assertStringContainsString('<h2>Custom projects</h2>', $output);
    $this->assertStringContainsString('<h2 class="us-title">Upgrade status test error', $output);
    $this->assertStringContainsString('UpgradeStatusTestErrorController.php', $output);

    // Test codeclimate output.
    $output = $run('upgrade-status:analyze', ['upgrade_status_test_error'], ['format' => 'codeclimate'] + $existing, 3);
    $this->assertStringContainsString('"type": "issue"', $output);
    $this->assertStringContainsString('check_name', $output);
    $this->assertStringContainsString('description', $output);
    $this->assertStringContainsString('categories', $output);
    $this->assertStringContainsString('Compatibility', $output);
    $this->assertStringContainsString('location', $output);
    $this->assertStringContainsString('fingerprint', $output);
    $this->assertStringContainsString('severity', $output);
  }

}
