<?php

namespace Drupal\advupdate\Render;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Component\Render\MarkupTrait;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\update\UpdateFetcherInterface;
use Drupal\update\UpdateManagerInterface;

/**
 * Render safe html output of update details.
 *
 * Update details can be considered safe as it is the project names and update
 * links provided by the Drupal.org project infrastructure. In preparation of
 * the output, strings are run through the translation and sanitation.
 */
class UpdateDetailsMarkup implements MarkupInterface {

  use MarkupTrait;

  /**
   * Create safe markup output of the project update details.
   *
   * @param array $project_data
   *   Output of update_calculate_project_data($available).
   *
   * @return \Drupal\Component\Render\MarkupInterface
   *   The UpdateDetailsMarkup instance.
   */
  public static function createFromProjectData(array $project_data) {

    // This will be a nested array. The first key is the kind of project, which
    // can be either 'enabled', 'disabled', 'manual' (projects which require
    // manual updates, such as core). Then, each sub-array is an array of
    // projects of that type, indexed by project short name, and containing an
    // array of data for items.
    $projects = [];

    foreach ($project_data as $name => $project) {
      // Filter out projects which are up to date already.
      if ($project['status'] == UpdateManagerInterface::CURRENT) {
        continue;
      }
      // The project name to display can vary based on the info we have.
      if (!empty($project['title'])) {
        $project_name = $project['title'];
      }
      elseif (!empty($project['info']['name'])) {
        $project_name = $project['info']['name'];
      }
      else {
        $project_name = $name;
      }
      if ($project['project_type'] == 'theme' || $project['project_type'] == 'theme-disabled') {
        $project_name .= ' ' . t('(Theme)');
      }

      if (empty($project['recommended'])) {
        // If we don't know what to recommend they upgrade to, we should skip
        // the project entirely.
        continue;
      }

      $recommended_release = $project['releases'][$project['recommended']];

      switch ($project['status']) {
        case UpdateManagerInterface::NOT_SECURE:
        case UpdateManagerInterface::REVOKED:
          $project_name .= ' ' . t('(Security update)');
          break;

        case UpdateManagerInterface::NOT_SUPPORTED:
          $project_name .= ' ' . t('(Unsupported)');
          break;

        case UpdateFetcherInterface::UNKNOWN:
        case UpdateFetcherInterface::NOT_FETCHED:
        case UpdateFetcherInterface::NOT_CHECKED:
        case UpdateManagerInterface::NOT_CURRENT:
          break;

        default:
          // Jump out of the switch and onto the next project in foreach.
          continue 2;
      }

      if (!empty($project['link'])) {
        $project_name = Link::fromTextAndUrl(
          $project_name,
          Url::fromUri(
            $project['link'],
            ['absolute' => TRUE]
          )
        )->toString();
      }

      // Create an entry for this project and use the project title
      // and additional information.
      $entry = [
        'title' => [
          '#markup' => t("<dt>@project</dt>\n", [
            '@project' => $project_name,
          ]),
        ],
        'info' => [
          '#markup' => t("<dd>Installed: @version <br /> Recommended: @recommended <br /> Release notes: @link</dd><dd>&nbsp;</dd>\n", [
            '@version' => $project['existing_version'],
            '@recommended' => $recommended_release['version'],
            '@link' => Link::fromTextAndUrl(
              $recommended_release['release_link'],
              Url::fromUri(
                $recommended_release['release_link'],
                ['absolute' => TRUE]
              )
            )->toString(),
          ]),
        ],
      ];

      // Based on what kind of project this is, save the entry into the
      // appropriate sub-array.
      switch ($project['project_type']) {
        case 'core':
          // Core needs manual updates at this time.
          $projects['manual'][$name] = $entry;
          break;

        case 'module':
        case 'theme':
          $projects['enabled'][$name] = $entry;
          break;

        case 'module-disabled':
        case 'theme-disabled':
          $projects['disabled'][$name] = $entry;
          break;
      }
    }

    if (!empty($projects['enabled'])) {
      $projects['enabled']['#prefix'] = "<dl>\n";
      $projects['enabled']['#suffix'] = "</dl>\n";

      $build['projects'] = [
        '#markup' => t("<h3>Enabled</h3>\n"),
        'projects' => $projects['enabled'],
      ];
    }

    if (!empty($projects['disabled'])) {
      $projects['disabled']['#prefix'] = "<dl>\n";
      $projects['disabled']['#suffix'] = "</dl>\n";

      $build['disabled_projects'] = [
        '#markup' => t("<h3>Disabled</h3>\n"),
        'projects' => $projects['disabled'],
      ];
    }

    if (!empty($projects['manual'])) {
      $projects['manual']['#prefix'] = "<dl>\n";
      $projects['manual']['#suffix'] = "</dl>\n";

      $build['manual_updates'] = [
        '#markup' => t("<h3>Manual updates required</h3>\n"),
        'projects' => $projects['manual'],
      ];
    }

    // Render output into MarkupInterface to allow html output.
    $renderer = \Drupal::service('renderer');
    $safe_string = new static();
    $safe_string->string = $renderer->render($build);
    return $safe_string;
  }

  /**
   * Overriden MarkupTrait::create method to preven it being used this way.
   *
   * @param string $string
   *   Input string to turn into a safe string.
   *
   * @return string
   *   The string 'Not permitted'.
   */
  public static function create($string) {
    return 'Not permitted.';
  }

}
