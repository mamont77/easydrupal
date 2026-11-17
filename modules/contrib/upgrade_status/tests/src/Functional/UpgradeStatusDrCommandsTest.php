<?php

namespace Drupal\Tests\upgrade_status\Functional;

use Drupal\upgrade_status\Command\AnalyzeCommand;
use Drupal\upgrade_status\Command\ReportCommand;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests the commands for vendor/bin/dr.
 *
 * @coversDefaultClass \Drupal\upgrade_status\Command\AnalyzeCommand
 *
 * @group upgrade_status
 */
#[RunTestsInSeparateProcesses]
#[Group('upgrade_status')]
class UpgradeStatusDrCommandsTest extends UpgradeStatusCommandsTestBase {

  /**
   * Tests the command for vendor/bin/dr.
   */
  public function testDrCommand() {
    // Drupal 11.4 and later finds commands with the AsCommand attribute.
    if (!$this->container->has(AnalyzeCommand::class)) {
      $this->markTestSkipped('vendor/bin/dr does not run commands of modules before Drupal 11.4.');
    }
    $command = $this->container->get(AnalyzeCommand::class);
    $this->assertSame('upgrade-status:analyze', $command->getName());
    $this->assertSame(['us-a'], $command->getAliases());

    // The command tester runs the command object, so the name is not used.
    $this->assertCommand(function (string $name, array $projects, array $options, int $expected_code) use ($command): string {
      $input = ['projects' => $projects];
      foreach ($options as $option => $value) {
        $input['--' . $option] = $value;
      }
      $tester = new CommandTester($command);
      $this->assertSame($expected_code, $tester->execute($input));
      return $tester->getDisplay();
    });

    $command = $this->container->get(ReportCommand::class);
    $this->assertSame('upgrade-status:report', $command->getName());
    $this->assertSame(['us-r'], $command->getAliases());
    $this->assertReport(function (array $options) use ($command): string {
      $input = [];
      foreach ($options as $option => $value) {
        $input['--' . $option] = $value;
      }
      $tester = new CommandTester($command);
      $this->assertSame(0, $tester->execute($input));
      return $tester->getDisplay();
    });
  }

}
