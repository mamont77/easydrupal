#!/usr/bin/env php
<?php

/**
 * @file
 * Fetch Drupal module changelogs from drupal.org release pages.
 *
 * Usage:
 *   ddev exec php [skill-directory]/scripts/fetch_changelog.php drupal/admin_toolbar 3.6.2 3.6.3
 *   ddev exec php [skill-directory]/scripts/fetch_changelog.php drupal/webform 6.2.0 7.0.0 --format=json
 */

/**
 * Fetch and parse Drupal module changelogs.
 */
class ChangelogFetcher {

  /**
   * Module name without drupal/ prefix.
   *
   * @var string
   */
  protected string $module;

  /**
   * Current version.
   *
   * @var string
   */
  protected string $oldVersion;

  /**
   * New version.
   *
   * @var string
   */
  protected string $newVersion;

  /**
   * Base URL for drupal.org.
   *
   * @var string
   */
  protected string $baseUrl;

  /**
   * Constructor.
   *
   * @param string $module
   *   Module name (e.g., 'drupal/admin_toolbar').
   * @param string $old_version
   *   Current version (e.g., '3.6.2').
   * @param string $new_version
   *   New version (e.g., '3.6.3').
   */
  public function __construct(string $module, string $old_version, string $new_version) {
    $this->module = str_replace('drupal/', '', $module);
    $this->oldVersion = $old_version;
    $this->newVersion = $new_version;
    $this->baseUrl = "https://www.drupal.org/project/{$this->module}";
  }

  /**
   * Fetch changelog information.
   *
   * @return array
   *   Changelog data array.
   */
  public function fetch(): array {
    $result = [
      'module' => $this->module,
      'old_version' => $this->oldVersion,
      'new_version' => $this->newVersion,
      'release_url' => "{$this->baseUrl}/releases/{$this->newVersion}",
      'changes' => [],
      'breaking_changes' => [],
      'security_notes' => [],
      'source' => NULL,
    ];

    // Try drupal.org first.
    $changelog = $this->fetchFromDrupalOrg();
    if ($changelog) {
      $result = array_merge($result, $changelog);
      $result['source'] = 'drupal.org';
      return $result;
    }

    // Try local CHANGELOG file.
    $changelog = $this->fetchFromLocal();
    if ($changelog) {
      $result = array_merge($result, $changelog);
      $result['source'] = 'local';
      return $result;
    }

    // Fallback: just provide the URL.
    $result['changes'] = ['See release notes for details'];
    $result['source'] = 'none';
    return $result;
  }

  /**
   * Fetch changelog from drupal.org release page.
   *
   * @return array|null
   *   Changelog data or NULL if failed.
   */
  protected function fetchFromDrupalOrg(): ?array {
    $url = "{$this->baseUrl}/releases/{$this->newVersion}";

    $html = @file_get_contents($url, FALSE, stream_context_create([
      'http' => [
        'timeout' => 10,
        'user_agent' => 'Drupal Update Skill',
      ],
    ]));

    if ($html === FALSE) {
      fwrite(STDERR, "Warning: Could not fetch from drupal.org\n");
      return NULL;
    }

    return $this->parseHtml($html);
  }

  /**
   * Parse HTML content from drupal.org.
   *
   * @param string $html
   *   HTML content.
   *
   * @return array|null
   *   Parsed changelog data or NULL.
   */
  protected function parseHtml(string $html): ?array {
    $changes = [];
    $breaking_changes = [];
    $security_notes = [];

    // Suppress HTML parsing warnings.
    libxml_use_internal_errors(TRUE);
    $dom = new DOMDocument();
    $dom->loadHTML($html);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);

    // Find the main content area.
    $main_nodes = $xpath->query("//main");
    if ($main_nodes->length === 0) {
      // Fallback to body if no main element.
      $main_nodes = $xpath->query("//body");
      if ($main_nodes->length === 0) {
        return NULL;
      }
    }

    $main = $main_nodes->item(0);

    // Find the "Release notes" heading.
    $release_heading = NULL;
    $headings = $xpath->query(".//h2", $main);
    foreach ($headings as $heading) {
      if (trim($heading->textContent) === 'Release notes') {
        $release_heading = $heading;
        break;
      }
    }

    if ($release_heading === NULL) {
      return NULL;
    }

    // Extract content between "Release notes" and "Other releases" headings.
    $release_content = [];
    $current = $release_heading->nextSibling;

    while ($current !== NULL) {
      // Stop at "Other releases" heading.
      if ($current->nodeName === 'h2') {
        break;
      }

      if ($current->nodeType === XML_ELEMENT_NODE) {
        // Extract text from paragraphs.
        if ($current->nodeName === 'p') {
          $text = trim($current->textContent);
          if (!empty($text) && strlen($text) < 300) {
            $release_content[] = ['type' => 'text', 'content' => $text];
          }
        }
        // Extract list items.
        elseif ($current->nodeName === 'ul' || $current->nodeName === 'ol') {
          $items = $xpath->query(".//li", $current);
          foreach ($items as $item) {
            $text = trim($item->textContent);
            if (!empty($text) && strlen($text) < 200) {
              $release_content[] = ['type' => 'list', 'content' => $text];
            }
          }
        }
        // Extract div content (drupal.org sometimes wraps content in divs).
        elseif ($current->nodeName === 'div') {
          $paragraphs = $xpath->query(".//p | .//li", $current);
          foreach ($paragraphs as $p) {
            $text = trim($p->textContent);
            if (!empty($text) && strlen($text) < 300) {
              $release_content[] = ['type' => $p->nodeName === 'li' ? 'list' : 'text', 'content' => $text];
            }
          }
          // Also check for direct text content in divs.
          $direct_text = trim($current->textContent);
          if (!empty($direct_text) && strlen($direct_text) < 300 && $paragraphs->length === 0) {
            $release_content[] = ['type' => 'text', 'content' => $direct_text];
          }
        }
      }
      // Also capture text nodes directly after the heading.
      elseif ($current->nodeType === XML_TEXT_NODE) {
        $text = trim($current->textContent);
        if (!empty($text) && strlen($text) < 300) {
          $release_content[] = ['type' => 'text', 'content' => $text];
        }
      }

      $current = $current->nextSibling;
    }

    // Categorize the extracted content.
    foreach ($release_content as $item) {
      $text = $item['content'];

      // Skip metadata lines.
      if (preg_match('/^(Created by:|Created on:|Last updated:)/i', $text)) {
        continue;
      }

      // Check for security-related content.
      if (preg_match('/security|vulnerability|CVE|SA-/i', $text)) {
        $security_notes[] = $text;
      }
      // Check for breaking changes.
      elseif (preg_match('/breaking|incompatible|removed|deprecated/i', $text)) {
        $breaking_changes[] = $text;
      }
      // Regular changes.
      else {
        $changes[] = $text;
      }
    }

    // Limit results.
    $changes = array_slice($changes, 0, 10);

    if (empty($changes) && empty($breaking_changes) && empty($security_notes)) {
      return NULL;
    }

    return [
      'changes' => $changes,
      'breaking_changes' => $breaking_changes,
      'security_notes' => $security_notes,
    ];
  }

  /**
   * Fetch changelog from local vendor directory.
   *
   * @return array|null
   *   Changelog data or NULL if not found.
   */
  protected function fetchFromLocal(): ?array {
    $paths = [
      "vendor/drupal/{$this->module}/CHANGELOG.md",
      "vendor/drupal/{$this->module}/CHANGELOG.txt",
      "vendor/drupal/{$this->module}/CHANGES.md",
      "vendor/drupal/{$this->module}/CHANGES.txt",
    ];

    foreach ($paths as $path) {
      if (file_exists($path)) {
        $content = file_get_contents($path);
        if ($content !== FALSE) {
          $changes = $this->parseChangelogContent($content, $this->newVersion);
          if (!empty($changes)) {
            return [
              'changes' => $changes,
              'breaking_changes' => [],
              'security_notes' => [],
            ];
          }
        }
      }
    }

    return NULL;
  }

  /**
   * Parse changelog content for a specific version.
   *
   * @param string $content
   *   Changelog file content.
   * @param string $version
   *   Version to extract.
   *
   * @return array
   *   Array of changes.
   */
  protected function parseChangelogContent(string $content, string $version): array {
    $changes = [];

    // Look for version header.
    $pattern = '/^#+\s*(?:Version\s+)?' . preg_quote($version, '/') . '.*$/mi';
    if (!preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
      return $changes;
    }

    $start_pos = $matches[0][1] + strlen($matches[0][0]);

    // Find next version header.
    $next_pattern = '/^#+\s*(?:Version\s+)?\d+\.\d+/mi';
    if (preg_match($next_pattern, $content, $next_matches, PREG_OFFSET_CAPTURE, $start_pos)) {
      $section = substr($content, $start_pos, $next_matches[0][1] - $start_pos);
    }
    else {
      $section = substr($content, $start_pos);
    }

    // Extract bullet points.
    $lines = explode("\n", $section);
    foreach ($lines as $line) {
      $line = trim($line);
      if (preg_match('/^[-*]\s+(.+)$/', $line, $matches)) {
        $change = trim($matches[1]);
        if (!empty($change) && strlen($change) < 200) {
          $changes[] = $change;
        }
      }
    }

    return array_slice($changes, 0, 10);
  }

}

/**
 * Format changelog data for output.
 *
 * @param array $data
 *   Changelog data.
 * @param string $format
 *   Output format ('markdown', 'json', or 'text').
 *
 * @return string
 *   Formatted output.
 */
function format_output(array $data, string $format = 'markdown'): string {
  if ($format === 'json') {
    return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  }

  if ($format === 'text') {
    $output = [];
    $output[] = "Module: {$data['module']}";
    $output[] = "Update: {$data['old_version']} → {$data['new_version']}";
    $output[] = "Release URL: {$data['release_url']}";
    $output[] = "";

    if (!empty($data['security_notes'])) {
      $output[] = "Security Notes:";
      foreach ($data['security_notes'] as $note) {
        $output[] = "  - $note";
      }
      $output[] = "";
    }

    if (!empty($data['breaking_changes'])) {
      $output[] = "Breaking Changes:";
      foreach ($data['breaking_changes'] as $change) {
        $output[] = "  - $change";
      }
      $output[] = "";
    }

    if (!empty($data['changes'])) {
      $output[] = "Changes:";
      foreach ($data['changes'] as $change) {
        $output[] = "  - $change";
      }
    }
    else {
      $output[] = "No detailed changes available.";
    }

    return implode("\n", $output);
  }

  // Markdown format (default).
  $output = [];
  $output[] = "### {$data['module']}: {$data['old_version']} → {$data['new_version']}";
  $output[] = "";
  $output[] = "**Release:** {$data['release_url']}";
  $output[] = "";

  if (!empty($data['security_notes'])) {
    $output[] = "**🔒 Security Notes:**";
    foreach ($data['security_notes'] as $note) {
      $output[] = "- $note";
    }
    $output[] = "";
  }

  if (!empty($data['breaking_changes'])) {
    $output[] = "**⚠️ Breaking Changes:**";
    foreach ($data['breaking_changes'] as $change) {
      $output[] = "- $change";
    }
    $output[] = "";
  }

  if (!empty($data['changes'])) {
    $output[] = "**Changes:**";
    foreach ($data['changes'] as $change) {
      $output[] = "- $change";
    }
  }
  else {
    $output[] = "See release notes for details.";
  }

  return implode("\n", $output);
}

/**
 * Main entry point.
 */
function main(): void {
  global $argc, $argv;

  if ($argc < 4) {
    fwrite(STDERR, "Usage: php fetch_changelog.php MODULE OLD_VERSION NEW_VERSION [--format=FORMAT]\n");
    fwrite(STDERR, "Example: php fetch_changelog.php drupal/admin_toolbar 3.6.2 3.6.3\n");
    exit(1);
  }

  $module = $argv[1];
  $old_version = $argv[2];
  $new_version = $argv[3];
  $format = 'markdown';

  // Parse optional format argument.
  if ($argc >= 5 && str_starts_with($argv[4], '--format=')) {
    $format = substr($argv[4], 9);
    if (!in_array($format, ['markdown', 'json', 'text'])) {
      fwrite(STDERR, "Error: Invalid format. Use markdown, json, or text.\n");
      exit(1);
    }
  }

  $fetcher = new ChangelogFetcher($module, $old_version, $new_version);
  $data = $fetcher->fetch();
  $output = format_output($data, $format);

  echo $output . "\n";
}

// Run the script.
main();
