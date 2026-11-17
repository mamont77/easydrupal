<?php

namespace Drupal\Tests\upgrade_status\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\upgrade_status\ProjectCollector;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Markdown, HTML and CSV formats of scan results.
 *
 * @coversDefaultClass \Drupal\upgrade_status\ScanResultFormatter
 *
 * @group upgrade_status
 */
#[RunTestsInSeparateProcesses]
#[Group('upgrade_status')]
final class ScanResultFormatterTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'update',
    'upgrade_status',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'update']);
  }

  /**
   * Tests the Markdown format.
   */
  public function testMarkdown(): void {
    $next_major = ProjectCollector::getDrupalCoreMajorVersion() + 1;
    $extension = $this->container->get('extension.list.module')->get('upgrade_status');
    $formatter = $this->container->get('upgrade_status.result_formatter');
    $storage = $this->container->get('keyvalue')->get('upgrade_status_scan_results');
    $date = $this->container->get('date.formatter')->format(1767225600);
    $root = DRUPAL_ROOT . '/modules/custom/example';
    $path = (preg_match('!/web$!', DRUPAL_ROOT) ? 'web/' : '') . 'modules/custom/example';
    $label = $extension->info['name'] . (!empty($extension->info['version']) ? ' ' . $extension->info['version'] : '');

    // A project without scan results is reported as such.
    $expected = <<<MARKDOWN
## $label (upgrade_status)

No analysis results.

MARKDOWN;
    $this->assertSame($expected, $formatter->formatMarkdownResult($extension));

    $storage->set('upgrade_status', [
      'date' => 1767225600,
      'data' => [
        'totals' => [
          'errors' => 5,
          'file_errors' => 5,
          'upgrade_status_split' => ['error' => 2, 'warning' => 3],
        ],
        'files' => [
          "$root/example.module" => [
            'messages' => [
              [
                'message' => "Call to deprecated function old_function().\nDeprecated in drupal:11.0.0 and is removed from drupal:12.0.0.  Use new_function() instead.",
                'line' => 10,
                'upgrade_status_category' => 'safe',
              ],
              [
                'message' => 'Call to deprecated function other_function(). Deprecated in drupal:11.5.0 and is removed from drupal:13.0.0.',
                'line' => 12,
                'upgrade_status_category' => 'ignore',
              ],
              [
                'message' => 'Call to deprecated function rector_function(). Deprecated in drupal:11.1.0 and is removed from drupal:12.0.0.',
                'line' => 14,
                'upgrade_status_category' => 'rector',
              ],
            ],
          ],
          "$root/example.info.yml" => [
            'messages' => [
              [
                'message' => 'Value of core_version_requirement: ^11 is not compatible with the next major version of Drupal core.',
                'line' => 0,
                'upgrade_status_category' => 'requirements',
              ],
            ],
          ],
          "$root/src/Example.php" => [
            'messages' => [
              [
                'message' => 'Call to deprecated method oldMethod(). Deprecated in drupal:11.0.0 and is removed from drupal:12.0.0.',
                'line' => 5,
                'upgrade_status_category' => 'old',
              ],
            ],
          ],
        ],
      ],
    ]);

    // The command for rector has the path of the project from the root of
    // the codebase.
    $project_path = rtrim($formatter->getDisplayPath(DRUPAL_ROOT . '/' . $extension->getPath()), '/');
    $expected = <<<MARKDOWN
## $label (upgrade_status)

Analyzed $date. 2 errors found. 3 warnings found. +1 after Drupal $next_major.

### Fix now with rector

drupal-rector can fix these automatically. Install it with `composer require --dev palantirnet/drupal-rector`, copy `vendor/palantirnet/drupal-rector/rector.php` next to the composer.json file of the site, then run `vendor/bin/rector process $project_path`. See https://www.drupal.org/project/rector.

- $path/example.module
  - Line 14: Call to deprecated function rector_function(). Deprecated in drupal:11.1.0 and is removed from drupal:12.0.0.

### Fix now manually

drupal-rector does not fix these yet. Fix these manually.

- $path/example.module
  - Line 10: Call to deprecated function old_function(). Deprecated in drupal:11.0.0 and is removed from drupal:12.0.0. Use new_function() instead.
- $path/src/Example.php
  - Line 5: Call to deprecated method oldMethod(). Deprecated in drupal:11.0.0 and is removed from drupal:12.0.0.

### Update requirements

Requirements and dependencies in info files, composer.json files and recipes that do not work with Drupal $next_major. Update these once the code is compatible.

- $path/example.info.yml
  - Value of core_version_requirement: ^11 is not compatible with the next major version of Drupal core.

### After Drupal $next_major

These are removed in later versions, and are not required to fix for Drupal $next_major.

- $path/example.module
  - Line 12: Call to deprecated function other_function(). Deprecated in drupal:11.5.0 and is removed from drupal:13.0.0.

MARKDOWN;
    $this->assertSame($expected, $formatter->formatMarkdownResult($extension));

    // A project without issues gets one line after the heading.
    $storage->set('upgrade_status', [
      'date' => 1767225600,
      'data' => [
        'totals' => ['errors' => 0, 'file_errors' => 0],
        'files' => [],
      ],
    ]);
    $expected = <<<MARKDOWN
## $label (upgrade_status)

Analyzed $date. No known issues found.

MARKDOWN;
    $this->assertSame($expected, $formatter->formatMarkdownResult($extension));

    // Messages for later versions are not problems. A project with only
    // those has no problems, and the number is on top of that. The page of
    // the project counts no file with problems, and the files have notices.
    $later = 'Call to deprecated function other_function(). Deprecated in drupal:11.5.0 and is removed from drupal:13.0.0.';
    $messages = [
      ['message' => $later, 'line' => 12, 'upgrade_status_category' => 'ignore'],
      ['message' => $later, 'line' => 14, 'upgrade_status_category' => 'ignore'],
    ];
    $storage->set('upgrade_status', [
      'date' => 1767225600,
      'data' => [
        'totals' => ['errors' => 2, 'file_errors' => 2, 'upgrade_status_category' => ['ignore' => 2]],
        'files' => ["$root/example.module" => ['messages' => $messages]],
      ],
    ]);
    $markdown = $formatter->formatMarkdownResult($extension);
    $this->assertStringContainsString("Analyzed $date. No problems found. +2 after Drupal $next_major.\n", $markdown);
    $this->assertStringContainsString("### After Drupal $next_major", $markdown);
    $build = $formatter->formatResult($extension);
    $this->assertSame(['No problems found'], array_map('strval', array_slice($build['#meta'], -1)));
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->assertStringContainsString('<span class="us-file__count">2 notices</span>', $html);
    $this->assertStringNotContainsString('2 problems', $html);

    // A contributed project without a compatible release has the advice to
    // look for a fix in the issues first. Messages with a fix for a newer
    // Drupal core version have that version.
    $newer = 'Call to deprecated function newer_function(). Deprecated in drupal:11.9.0 and is removed from drupal:12.0.0.';
    $messages = [['message' => $newer, 'line' => 3, 'upgrade_status_category' => 'later']];
    $storage->set('upgrade_status', [
      'date' => 1767225600,
      'data' => [
        'totals' => ['errors' => 1, 'file_errors' => 1, 'upgrade_status_split' => ['warning' => 1]],
        'files' => ["$root/example.module" => ['messages' => $messages]],
      ],
    ]);
    $original_info = $extension->info;
    $extension->info['upgrade_status_type'] = ProjectCollector::TYPE_CONTRIB;
    $extension->info['project'] = 'upgrade_status';
    $extension->info['upgrade_status_next'] = ProjectCollector::NEXT_COLLABORATE;
    $markdown = $formatter->formatMarkdownResult($extension);
    $this->assertStringContainsString('is a contributed project without a compatible release yet. Look for an issue or merge request with a fix first.', $markdown);
    $this->assertStringContainsString("Issues: https://drupal.org/project/issues/upgrade_status?text=Drupal+$next_major&search=Drupal+$next_major&state=all\n", $markdown);
    $this->assertStringContainsString('### Raise core requirement and fix', $markdown);
    $this->assertStringContainsString('The fixes work from Drupal 11.9. Only fix these if the project can stop supporting older Drupal core versions', $markdown);
    $extension->info['upgrade_status_type'] = ProjectCollector::TYPE_CUSTOM;
    $extension->info['upgrade_status_next'] = ProjectCollector::NEXT_MANUAL;
    $markdown = $formatter->formatMarkdownResult($extension);
    $this->assertStringNotContainsString('Look for an issue', $markdown);
    $this->assertStringContainsString('### Update core and fix', $markdown);
    $this->assertStringContainsString('The fixes work from Drupal 11.9. Update Drupal core first.', $markdown);
    $extension->info = $original_info;
    $storage->set('upgrade_status', [
      'date' => 1767225600,
      'data' => [
        'totals' => ['errors' => 0, 'file_errors' => 0],
        'files' => [],
      ],
    ]);

    // The report has a title, and shows if a project is custom or contributed.
    $extension->info['upgrade_status_type'] = ProjectCollector::TYPE_CONTRIB;
    $version = ProjectCollector::getDrupalCoreMajorVersion() + 1;
    $meta = $formatter->getDocumentMeta();
    $expected = <<<MARKDOWN
# Upgrade Status results for Drupal $version

$meta

Errors are the problems to fix now. Warnings are in the other categories, except "After Drupal $version".

## $label (upgrade_status)

Contributed project. Analyzed $date. No known issues found.

MARKDOWN;
    $this->assertSame($expected, $formatter->formatMarkdownReport([$extension]));
    $extension->info['upgrade_status_type'] = ProjectCollector::TYPE_CUSTOM;
    $this->assertStringContainsString("\n\nCustom project. Analyzed $date.", $formatter->formatMarkdownReport([$extension]));
  }

  /**
   * Tests the HTML export of a project without results and without issues.
   *
   * The export shows the same messages as the result page, but not the links
   * to export the project again.
   */
  public function testHtmlExport(): void {
    $extension = $this->container->get('extension.list.module')->get('upgrade_status');
    $formatter = $this->container->get('upgrade_status.result_formatter');
    $storage = $this->container->get('keyvalue')->get('upgrade_status_scan_results');

    // A project without scan results is reported as such, not only by its
    // title.
    $html = (string) $formatter->buildExportResponse(['custom' => ['upgrade_status' => $extension]], 'html', 'export')->getContent();
    $this->assertStringContainsString('No analysis results available.', $html);
    // The link to the report page works outside the site too.
    $this->assertMatchesRegularExpression('@<a href="https?://[^"]+/admin/reports/upgrade-status">@', $html);

    // A project without issues is reported as such, also when test classes
    // were skipped.
    $storage->set('upgrade_status', [
      'date' => 1767225600,
      'data' => [
        'totals' => ['errors' => 0, 'file_errors' => 0, 'upgrade_status_test_classes_skipped' => TRUE],
        'files' => [],
      ],
    ]);
    $html = (string) $formatter->buildExportResponse(['custom' => ['upgrade_status' => $extension]], 'html', 'export')->getContent();
    $this->assertStringContainsString('<span>Analyzed ' . $this->container->get('date.formatter')->format(1767225600) . '</span>', $html);
    $this->assertStringContainsString('No known issues found.', $html);
    $this->assertStringContainsString('Test classes were not checked, because PHPUnit is not installed.', $html);
    $this->assertStringNotContainsString('Export as HTML', $html);
    $this->assertStringNotContainsString('Export as Markdown', $html);
  }

  /**
   * Tests the button that copies the path of a file on the result page.
   */
  public function testCopyPathButton(): void {
    $extension = $this->container->get('extension.list.module')->get('upgrade_status');
    $formatter = $this->container->get('upgrade_status.result_formatter');
    $module = $extension->getPath();
    $directory = DRUPAL_ROOT . '/' . $module;
    $message = [
      'message' => 'Call to deprecated function old_function(). Deprecated in drupal:11.0.0 and is removed from drupal:12.0.0.',
      'line' => 10,
      'upgrade_status_category' => 'safe',
    ];
    $this->container->get('keyvalue')->get('upgrade_status_scan_results')->set('upgrade_status', [
      'date' => 1767225600,
      'data' => [
        'totals' => ['errors' => 2, 'file_errors' => 2],
        'files' => [
          "$directory/src/Example.php" => ['messages' => [$message]],
          "$directory/src/ExampleTrait.php (in context of class Drupal\\example\\Example)" => ['messages' => [$message]],
        ],
      ],
    ]);

    // The result page shows the path relative to the project. The button
    // copies the path from the root of the codebase, without the context.
    $build = $formatter->formatResult($extension);
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $path = (preg_match('!/web$!', DRUPAL_ROOT) ? 'web/' : '') . $module;
    $this->assertSame(2, substr_count($html, 'data-us-copy='));
    $this->assertStringContainsString('<span class="us-file__dir">src/</span><span class="us-file__name">Example.php</span>', $html);
    $this->assertStringContainsString('<button type="button" class="us-file__copy js-show" data-us-copy="' . $path . '/src/Example.php" title="Copy path" aria-label="Copy the path of Example.php">', $html);
    $this->assertStringContainsString('data-us-copy="' . $path . '/src/ExampleTrait.php" title="Copy path" aria-label="Copy the path of ExampleTrait.php">', $html);

    // In an export the buttons are hidden until the script of the export
    // shows them.
    $html = (string) $formatter->buildExportResponse(['custom' => ['upgrade_status' => $extension]], 'html', 'export')->getContent();
    $this->assertSame(2, substr_count($html, '<button'));
    $this->assertStringContainsString('<button type="button" class="us-file__copy us-file__copy--export" hidden data-us-copy="' . $path . '/src/Example.php" title="Copy path" aria-label="Copy the path of Example.php">', $html);
    $this->assertStringContainsString('hidden data-us-copy="' . $path . '/src/ExampleTrait.php" title="Copy path"', $html);
    $this->assertStringContainsString('<span class="visually-hidden" role="status" data-us-copy-status="Path copied."></span>', $html);

    // The export has one script, and its Content Security Policy allows only
    // the code of that script.
    $this->assertSame(1, preg_match_all('@<script>(.*?)</script>@s', $html, $scripts));
    $this->assertStringContainsString('navigator.clipboard.writeText', $scripts[1][0]);
    $hash = base64_encode(hash('sha256', $scripts[1][0], TRUE));
    $this->assertStringContainsString("content=\"default-src 'none'; style-src 'unsafe-inline'; script-src 'sha256-$hash'; base-uri 'none'; form-action 'none'\">", $html);
  }

  /**
   * Tests the versions of the analysis tools in the results of a project.
   */
  public function testToolVersions(): void {
    $extension = $this->container->get('extension.list.module')->get('upgrade_status');
    $formatter = $this->container->get('upgrade_status.result_formatter');
    $checker = $this->container->get('upgrade_status.tool_update_checker');
    $storage = $this->container->get('upgrade_status.result_storage');
    $renderer = $this->container->get('renderer');
    $installed = $checker->getInstalledVersions();
    $result = [
      'date' => 1767225600,
      'data' => ['totals' => ['errors' => 0, 'file_errors' => 0], 'files' => []],
    ];

    // Exports name the installed versions next to their date.
    $line = 'Analysis tools: ' . $checker->formatVersions($installed) . '.';
    $storage->set('upgrade_status', $result);
    $this->assertStringEndsWith('. ' . $line, (string) $formatter->getDocumentMeta());
    $html = (string) $formatter->buildExportResponse(['custom' => ['upgrade_status' => $extension]], 'html', 'export')->getContent();
    $this->assertStringContainsString('. ' . $line . '</p>', $html);
    $markdown = $formatter->formatMarkdownReport(['upgrade_status' => $extension]);
    $this->assertStringContainsString('. ' . $line . "\n", $markdown);

    // Results of the installed versions have no note.
    $build = $formatter->formatResult($extension);
    $page = (string) $renderer->renderInIsolation($build);
    $this->assertStringNotContainsString('other versions', $page . $html . $markdown);

    // Results of other versions tell which versions made them, on the result
    // page and in the exports.
    $other = ['drupal/upgrade_status' => '5.0.0', 'phpstan/phpstan' => '0.0.1'];
    $storage->set('upgrade_status', $result + ['versions' => $other]);
    $note = 'These results were made with other versions of the analysis tools: Upgrade Status 5.0.0, PHPStan 0.0.1. Analyze the project again for results of the installed versions.';
    $build = $formatter->formatResult($extension);
    $this->assertStringContainsString($note, (string) $renderer->renderInIsolation($build));
    $this->assertStringContainsString($note, (string) $formatter->buildExportResponse(['custom' => ['upgrade_status' => $extension]], 'html', 'export')->getContent());
    $this->assertStringContainsString(' ' . $note . ' No known issues found.', $formatter->formatMarkdownResult($extension));

    // Results from before the versions were stored have no note.
    $this->container->get('keyvalue')->get('upgrade_status_scan_results')->set('upgrade_status', $result);
    $build = $formatter->formatResult($extension);
    $this->assertStringNotContainsString('other versions', (string) $renderer->renderInIsolation($build));
  }

  /**
   * Tests the CSV formats, with one row per message and per project.
   */
  public function testCsv(): void {
    $this->enableModules(['upgrade_status_test_error']);
    $formatter = $this->container->get('upgrade_status.result_formatter');
    $root = DRUPAL_ROOT . '/' . $this->container->get('extension.list.module')->getPath('upgrade_status_test_error');
    $path = (preg_match('!/web$!', DRUPAL_ROOT) ? 'web/' : '') . $this->container->get('extension.list.module')->getPath('upgrade_status_test_error');
    $message = fn (string $text, int $line, string $category): array => [
      'message' => $text,
      'line' => $line,
      'upgrade_status_category' => $category,
    ];
    $this->container->get('keyvalue')->get('upgrade_status_scan_results')->set('upgrade_status_test_error', [
      'date' => 1767225600,
      'data' => [
        'totals' => [
          'errors' => 4,
          'file_errors' => 4,
          'upgrade_status_next' => ProjectCollector::NEXT_MANUAL,
        ],
        'files' => [
          "$root/upgrade_status_test_error.module" => [
            'messages' => [
              $message("Call to deprecated function old_function().\nUse new_function().", 10, 'safe'),
              $message('Call to deprecated function rector_function().', 14, 'rector'),
              // Spreadsheet applications would run this as a formula.
              $message('=HYPERLINK("https://example.com")', 16, 'later'),
            ],
          ],
          "$root/upgrade_status_test_error.info.yml" => [
            'messages' => [
              $message('Value of core_version_requirement is not compatible.', 0, ''),
            ],
          ],
        ],
      ],
    ]);
    // The next step comes from the results, so they are stored first.
    $projects = $this->container->get('upgrade_status.project_collector')->collectProjects();
    $parse = fn (string $csv): array => array_map(fn ($line) => str_getcsv($line, ',', '"', ''), explode("\n", rtrim($csv, "\n")));
    $date = $this->container->get('date.formatter')->format(1767225600, 'custom', 'Y-m-d H:i:s');
    $next_major = ProjectCollector::getDrupalCoreMajorVersion() + 1;
    $project = [
      'Fix manually',
      'Upgrade status test error',
      'upgrade_status_test_error',
      'Custom module',
      'Installed',
      'N/A',
      'Incompatible',
      $date,
      '4',
      'Not applicable',
      'Not applicable',
      '',
    ];
    $header = [
      'Group',
      'Project',
      'Machine name',
      'Type',
      'Status',
      'Local version',
      "Local $next_major-ready",
      'Analysis date',
      'Problems',
      'Drupal.org version',
      "Drupal.org $next_major-ready",
      'Drupal.org issues',
    ];

    $list = [$projects['upgrade_status_test_error'], $projects['upgrade_status']];
    $module = "$path/upgrade_status_test_error.module";
    $info = "$path/upgrade_status_test_error.info.yml";

    // One row per message, and one row for a project without results.
    $rows = $parse($formatter->formatCsvResults($list));
    $this->assertSame([...$header, 'Category', 'File', 'Line', 'Message'], $rows[0]);
    $message = 'Call to deprecated function old_function(). Use new_function().';
    $this->assertSame([...$project, 'Fix now manually', $module, '10', $message], $rows[1]);
    $message = 'Call to deprecated function rector_function().';
    $this->assertSame([...$project, 'Fix now with rector', $module, '14', $message], $rows[2]);
    // The project is a custom project.
    $this->assertSame([...$project, 'Update core and fix', $module, '16', '\'=HYPERLINK("https://example.com")'], $rows[3]);
    $message = 'Value of core_version_requirement is not compatible.';
    $this->assertSame([...$project, 'Check manually', $info, '0', $message], $rows[4]);
    $this->assertCount(6, $rows);
    $this->assertSame(['upgrade_status', '', ''], [$rows[5][2], $rows[5][7], $rows[5][8]]);
    $this->assertSame(['', '', '', ''], array_slice($rows[5], 12));

    // One row per project, with the number of problems in each category.
    $rows = $parse($formatter->formatCsvProjects($list));
    $categories = [
      'Fix now with rector',
      'Fix now manually',
      'Update requirements',
      'Consider contrib deprecations',
      'Check manually',
      'Update core or raise core requirement and fix',
      "After Drupal $next_major",
    ];
    $this->assertSame([...$header, ...$categories], $rows[0]);
    // A message without a stored category is to check manually.
    $this->assertSame([...$project, '1', '1', '0', '0', '1', '1', '0'], $rows[1]);
    $this->assertSame(['', '', '', '', '', '', ''], array_slice($rows[2], 12));
    $this->assertCount(3, $rows);

    // The export is a CSV file with a byte order mark for spreadsheets.
    $response = $formatter->buildExportResponse(['custom' => [$projects['upgrade_status_test_error']]], 'csv', 'export');
    $this->assertStringStartsWith('text/csv', $response->headers->get('Content-Type'));
    $this->assertStringContainsString('filename="export.csv"', $response->headers->get('Content-Disposition'));
    $this->assertStringStartsWith("\xEF\xBB\xBFGroup,", (string) $response->getContent());

    // Export file names have the site name in ASCII and the date.
    $this->config('system.site')->set('name', 'Café & Co. Site')->save();
    $this->assertMatchesRegularExpression('/^upgrade-status-cafe-co-site-results-upgrade_status_test_error-\d{4}-\d{2}-\d{2}-\d{4}$/', $formatter->getExportFilename('results', 'upgrade_status_test_error'));
    // The name of a recipe is the name of its directory, with any characters.
    $this->assertMatchesRegularExpression('/^upgrade-status-cafe-co-site-results-my-recipe-\d{4}-/', $formatter->getExportFilename('results', 'my "recipe"'));
    $this->assertStringContainsString('For Café &amp; Co. Site, made on ', (string) $formatter->getDocumentMeta());
    $this->assertStringContainsString("\n\nFor Café & Co. Site, made on ", $formatter->formatMarkdownReport([]));
  }

  /**
   * Tests how messages are formatted on the result page.
   */
  public function testFormatMessage(): void {
    $formatter = $this->container->get('upgrade_status.result_formatter');

    // The deprecation versions become pills. The removal in the next major
    // version is shown as an error.
    [$message, $pills] = $formatter->formatMessage('Call to deprecated function old_function(). Deprecated in drupal:11.0.0 and is removed from drupal:12.0.0. Use \\Drupal\\Core\\Example::method() instead. See https://www.drupal.org/node/123', 12);
    $this->assertSame('Call to deprecated function <code>old_function()</code>. Use <code>\\Drupal\\Core\\Example::method()</code> instead. See <a href="https://www.drupal.org/node/123">drupal.org/node/123</a>', (string) $message);
    $this->assertSame([
      '<span class="us-pill us-pill--neutral">Deprecated in 11.0.0</span>',
      '<span class="us-pill us-pill--error">Removed in 12.0.0</span>',
    ], array_map('strval', $pills));

    // A removal in a later major version is neutral.
    [, $pills] = $formatter->formatMessage('Call to deprecated function other(). Deprecated in drupal:11.5.0 and is removed from drupal:13.0.0.', 12);
    $this->assertStringContainsString('us-pill--neutral">Removed in 13.0.0', (string) $pills[1]);

    // The other ways the versions are written, also for contributed
    // projects. Only the removal from Drupal core is an error.
    $cases = [
      'Call to deprecated method a() of class B: in drupal:10.1.0 and is removed from drupal:12.0.0. Use c() instead.' => [
        'Call to deprecated method <code>a()</code> of class B. Use <code>c()</code> instead.',
        ['Deprecated in 10.1.0', 'Removed in 12.0.0'],
      ],
      'The "x" service is deprecated in drupal:11.3.0 and is removed from drupal:12.0.0. There is no replacement.' => [
        'The &quot;x&quot; service is deprecated. There is no replacement.',
        ['Deprecated in 11.3.0', 'Removed in 12.0.0'],
      ],
      'Call to deprecated function d(). Deprecated in ai:1.2.0 and is removed from ai:2.0.0 and possibly even earlier in ai_agents.' => [
        'Call to deprecated function <code>d()</code>.',
        ['Deprecated in ai 1.2.0', 'Removed in ai 2.0.0, maybe earlier in ai_agents'],
      ],
      'Call to deprecated function e(). Deprecated in token:8.x-1.12.' => [
        'Call to deprecated function <code>e()</code>.',
        ['Deprecated in token 8.x-1.12'],
      ],
      'Checking access by default is deprecated in drupal:9.2.0 and an error will be thrown from drupal:10.0.0. Call f().' => [
        'Checking access by default is deprecated. Call <code>f()</code>.',
        ['Deprecated in 9.2.0', 'Fails in 10.0.0'],
      ],
    ];
    foreach ($cases as $original => [$expected_message, $expected_pills]) {
      [$message, $pills] = $formatter->formatMessage($original, 12);
      $this->assertSame($expected_message, (string) $message, $original);
      $this->assertSame($expected_pills, array_map(fn ($pill) => strip_tags((string) $pill), $pills), $original);
    }

    // Symfony versions, info file values and text in backticks.
    [$message, $pills] = $formatter->formatMessage('Since symfony/http-foundation 7.4: Use `->attributes` instead.', 12);
    $this->assertSame('Use <code>-&gt;attributes</code> instead.', (string) $message);
    $this->assertSame('<span class="us-pill us-pill--neutral">Deprecated in Symfony 7.4</span>', (string) $pills[0]);
    [$message] = $formatter->formatMessage('Value of core_version_requirement: ^10 || ^11 is not compatible with the next major version of Drupal core. <script>', 12);
    $this->assertSame('Value of <code>core_version_requirement: ^10 || ^11</code> is not compatible with the next major version of Drupal core. &lt;script&gt;', (string) $message);
  }

  /**
   * Tests the Code Climate severity of libraries that cannot be checked.
   */
  public function testCodeClimateUncheckedLibrary(): void {
    $extension = $this->container->get('extension.list.module')->get('upgrade_status');
    $start = "The 'a/b' library is not defined because the defining extension is not installed. ";
    $texts = [
      $start . 'Whether the library is deprecated cannot be checked.',
      // The text in results that were stored before the text changed.
      $start . 'Cannot decide if it is deprecated or not.',
      'Call to deprecated function a().',
    ];
    $messages = [];
    foreach ($texts as $line => $text) {
      $messages[] = ['message' => $text, 'line' => $line + 1, 'upgrade_status_category' => 'uncategorized'];
    }
    $this->container->get('keyvalue')->get('upgrade_status_scan_results')->set('upgrade_status', [
      'date' => 1767225600,
      'data' => [
        'totals' => ['errors' => 3, 'file_errors' => 3],
        'files' => [DRUPAL_ROOT . '/modules/custom/example/example.libraries.yml' => ['messages' => $messages]],
      ],
    ]);
    $issues = json_decode($this->container->get('upgrade_status.result_formatter')->formatCodeClimate([$extension]), TRUE);
    $this->assertSame(['info', 'info', 'minor'], array_column($issues, 'severity'));
  }

  /**
   * Tests the Checkstyle and Code Climate formats.
   */
  public function testCheckstyleAndCodeClimate(): void {
    $extension = $this->container->get('extension.list.module')->get('upgrade_status');
    $formatter = $this->container->get('upgrade_status.result_formatter');
    $this->container->get('keyvalue')->get('upgrade_status_scan_results')->set('upgrade_status', [
      'date' => 1767225600,
      'data' => [
        'totals' => ['errors' => 2, 'file_errors' => 2],
        'files' => [
          DRUPAL_ROOT . '/modules/custom/example/example.module' => [
            'messages' => [
              [
                'message' => 'Call to deprecated function a().',
                'line' => 10,
                'upgrade_status_category' => 'safe',
                'analyzer' => 'phpstan',
              ],
              ['message' => 'Call to deprecated function b().', 'line' => 12, 'upgrade_status_category' => 'later'],
            ],
          ],
        ],
      ],
    ]);

    $xml = new \SimpleXMLElement($formatter->formatCheckstyle([$extension]));
    $this->assertSame('modules/custom/example/example.module', (string) $xml->file['name']);
    $severities = [(string) $xml->file->error[0]['severity'], (string) $xml->file->error[1]['severity']];
    $this->assertSame(['error', 'warning'], $severities);

    $issues = json_decode($formatter->formatCodeClimate([$extension]), TRUE);
    $this->assertCount(2, $issues);
    $this->assertSame(['major', 'info'], array_column($issues, 'severity'));
    $this->assertSame(['phpstan', 'upgrade_status'], array_column($issues, 'check_name'));
    $this->assertSame('upgrade_status - Call to deprecated function a().', $issues[0]['description']);
  }

}
