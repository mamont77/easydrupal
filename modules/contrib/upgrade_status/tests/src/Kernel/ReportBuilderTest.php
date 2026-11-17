<?php

namespace Drupal\Tests\upgrade_status\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\upgrade_status\ProjectCollector;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the HTML document of the report.
 *
 * @group upgrade_status
 */
#[RunTestsInSeparateProcesses]
#[Group('upgrade_status')]
final class ReportBuilderTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'update',
    'upgrade_status',
    'upgrade_status_test_error',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'update']);
  }

  /**
   * Tests the document with all groups, and with some groups.
   */
  public function testRenderReport(): void {
    $this->container->get('keyvalue')->get('upgrade_status_scan_results')->set('upgrade_status_test_error', [
      'date' => 1767225600,
      'data' => [
        'totals' => [
          'errors' => 3,
          'file_errors' => 3,
          'upgrade_status_next' => ProjectCollector::NEXT_MANUAL,
          'upgrade_status_category' => ['safe' => 2, 'ignore' => 1],
        ],
        'files' => [],
      ],
    ]);
    $projects = $this->container->get('upgrade_status.project_collector')->collectProjects();
    // The version comes from the info file of a project.
    $projects['upgrade_status_test_error']->info['version'] = '<img src="https://example.com/track.png">';
    $environment = $this->container->get('upgrade_status.environment_checker')->getChecks();
    $builder = $this->container->get('upgrade_status.report_builder');
    $all = array_keys($this->container->get('upgrade_status.project_collector')->getNextStepInfo());

    $html = $builder->renderReport($projects, $all, $environment, $environment['status']);
    $this->assertStringStartsWith('<!doctype html>', trim($html));
    $this->assertStringContainsString('<h1>Upgrade Status report for Drupal ' . (ProjectCollector::getDrupalCoreMajorVersion() + 1) . '</h1>', $html);
    $this->assertStringContainsString('Not ready for Drupal ' . (ProjectCollector::getDrupalCoreMajorVersion() + 1) . ' yet', $html);
    $this->assertStringContainsString('Fix incompatibilities', $html);
    $this->assertStringContainsString('Available updates for all projects were never checked, so there are no update recommendations.', $html);
    // The report accuracy card is above the summary card.
    $this->assertLessThan(strpos($html, 'class="us-card us-summary"'), strpos($html, 'id="us-accuracy"'));
    $this->assertStringContainsString('id="edit-environment"', $html);
    $this->assertStringContainsString('<span>Drupal core and hosting environment</span>', $html);
    $this->assertMatchesRegularExpression('@<details[^>]+id="edit-manual"@', $html);
    $this->assertStringContainsString('<span>Fix manually</span>', $html);
    $this->assertStringContainsString('Upgrade status test error (upgrade_status_test_error)', $html);
    // The message for a later version is not one of the problems.
    // It is a second line under the problems.
    $this->assertStringContainsString('2 problems', $html);
    $this->assertStringContainsString('<span class="us-qualifier">+1 after Drupal ' . (ProjectCollector::getDrupalCoreMajorVersion() + 1) . '</span>', $html);
    $this->assertStringNotContainsString('3 problems', $html);
    $this->assertStringContainsString(', 2 problems in total', $html);

    // The document loads nothing from other places: values of info files are
    // text, and a policy blocks what gets through.
    $this->assertStringContainsString('&lt;img src=&quot;https://example.com/track.png&quot;&gt;', $html);
    $this->assertStringNotContainsString('<img', $html);
    $this->assertStringContainsString('<meta http-equiv="Content-Security-Policy" content="default-src \'none\';', $html);
    // The policy allows only the code of the one script of the document.
    $this->assertSame(1, preg_match_all('@<script>(.*?)</script>@s', $html, $scripts));
    $this->assertStringContainsString("script-src 'sha256-" . base64_encode(hash('sha256', $scripts[1][0], TRUE)) . "';", $html);

    // A document has no form controls and no links to pages of the site.
    $this->assertStringNotContainsString('<input', $html);
    $this->assertStringNotContainsString('<label', $html);
    $this->assertStringNotContainsString('Analyze selected', $html);
    $this->assertStringNotContainsString('upgrade-status/project', $html);
    $this->assertStringNotContainsString('Check manually</a>', $html);

    // Only the selected groups are listed, and the environment can be left
    // out. The summary still counts all projects.
    $html = $builder->renderReport($projects, [ProjectCollector::NEXT_RELAX], NULL, $environment['status']);
    $this->assertStringNotContainsString('id="edit-environment"', $html);
    $this->assertStringNotContainsString('id="edit-manual"', $html);
    $this->assertStringNotContainsString('(upgrade_status_test_error)', $html);
    // The bar describes its segments as text.
    $this->assertStringContainsString('Fix manually: 1', $html);
  }

  /**
   * Tests when the report accuracy card is yellow.
   */
  public function testAccuracyCard(): void {
    // Without newer analysis tools and projects without versions, only the
    // age of the update data counts.
    $this->setSetting('upgrade_status_check_tool_updates', FALSE);
    $builder = $this->container->get('upgrade_status.report_builder');
    $renderer = $this->container->get('renderer');
    $state = $this->container->get('state');
    $now = $this->container->get('datetime.time')->getRequestTime();

    // Available updates checked more than a day ago are to fix.
    $state->set('update.last_check', $now - 86400 - 60);
    $card = $builder->buildAccuracyCard([], [], NULL);
    $html = (string) $renderer->renderInIsolation($card);
    $this->assertStringContainsString('us-notice--warning', $html);
    $this->assertStringContainsString('1 to fix', $html);

    // Within a day, the card is not yellow, and only shows when available
    // updates were checked.
    $state->set('update.last_check', $now - 3600);
    $card = $builder->buildAccuracyCard([], [], NULL);
    $html = (string) $renderer->renderInIsolation($card);
    $this->assertStringContainsString('us-notice--quiet', $html);
    $this->assertStringNotContainsString('to fix', $html);
    $this->assertStringContainsString('Available updates for all projects were checked 1 hour ago.', $html);
    $this->assertSame(1, substr_count($html, '<li class="us-notice__item">'));
  }

  /**
   * Tests the versions of the analysis tools in the report.
   */
  public function testToolVersions(): void {
    $this->setSetting('upgrade_status_check_tool_updates', FALSE);
    $this->container->get('state')->set('update.last_check', $this->container->get('datetime.time')->getRequestTime() - 3600);
    $checker = $this->container->get('upgrade_status.tool_update_checker');
    $storage = $this->container->get('upgrade_status.result_storage');
    $builder = $this->container->get('upgrade_status.report_builder');
    $renderer = $this->container->get('renderer');
    $environment = $this->container->get('upgrade_status.environment_checker')->getChecks();
    $installed = $checker->getInstalledVersions();
    $line = 'Analysis tools: ' . $checker->formatVersions($installed) . '.';
    $result = [
      'date' => 1767225600,
      'data' => [
        'totals' => ['errors' => 2, 'file_errors' => 2, 'upgrade_status_next' => ProjectCollector::NEXT_MANUAL],
        'files' => [],
      ],
    ];

    // Results of the installed versions are not a problem. The report page
    // names the versions in the accuracy card.
    $storage->set('upgrade_status_test_error', $result);
    $projects = $this->container->get('upgrade_status.project_collector')->collectProjects();
    $this->assertArrayNotHasKey('results', $builder->getAccuracyNotes($projects, $projects));
    $card = $builder->buildAccuracyCard($projects, $projects, []);
    $html = (string) $renderer->renderInIsolation($card);
    $this->assertStringNotContainsString('other versions', $html);
    $this->assertStringContainsString('<p class="us-notice__item-body">' . $line . '</p>', $html);

    // An export has the versions once, next to its date.
    $html = $builder->renderReport($projects, [ProjectCollector::NEXT_MANUAL], NULL, NULL);
    $this->assertSame(1, substr_count($html, 'Analysis tools: '));
    $this->assertMatchesRegularExpression('@<p class="us-document-meta">[^<]*ade on [^<]*\. ' . preg_quote($line, '@') . '</p>@', $html);
    $markdown = $builder->formatMarkdownReport($projects, $projects, $environment, []);
    $this->assertSame(1, substr_count($markdown, 'Analysis tools: '));
    // Removing a project is not to be done right away: the advice to agree on
    // the list comes before the command.
    $remove = $builder->formatMarkdownReport($projects, $projects, $environment, ['remove']);
    $agree = strpos($remove, 'Agree with the people responsible for the site first');
    $this->assertNotFalse($agree);
    $this->assertGreaterThan($agree, strpos($remove, '`composer remove drupal/`'));
    $this->assertMatchesRegularExpression('@^.*ade on .*\. ' . preg_quote($line, '@') . '$@m', $markdown);

    // Results of other versions are to fix, and the project is named.
    $storage->set('upgrade_status_test_error', $result + ['versions' => ['phpstan/phpstan' => '0.0.1'] + $installed]);
    $projects = $this->container->get('upgrade_status.project_collector')->collectProjects();
    $note = '1 project was analyzed with other versions of the analysis tools than the installed ones, so the results may be outdated. Analyze the project again: Upgrade status test error.';
    $notes = $builder->getAccuracyNotes($projects, $projects);
    $this->assertSame(1, $notes['results'][0]);
    $this->assertSame($note, (string) $notes['results'][1]);
    $card = $builder->buildAccuracyCard($projects, $projects, []);
    $html = (string) $renderer->renderInIsolation($card);
    $this->assertStringContainsString('us-notice--warning', $html);
    $this->assertStringContainsString(count($notes) . ' to fix', $html);
    $this->assertStringContainsString($note, $html);
    $this->assertStringContainsString('- ' . $note, $builder->formatMarkdownReport($projects, $projects, $environment, []));

    // Results from before the versions were stored are not to fix.
    $this->container->get('keyvalue')->get('upgrade_status_scan_results')->set('upgrade_status_test_error', $result);
    $storage->resetTotals();
    $this->assertArrayNotHasKey('results', $builder->getAccuracyNotes($projects, $projects));
  }

}
