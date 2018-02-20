<?php

namespace Drupal\advupdate\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\update\UpdateManagerInterface;
use Drupal\update\UpdateFetcherInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Controller routines for page example routes.
 */
class AdvUpdateController extends ControllerBase {

  public function simple() {

    module_load_include('inc', 'update', 'update.manager');

    if (!_update_manager_check_backends($build, 'update')) {
      return $build;
    }

    $available = update_get_available(TRUE);
    if (empty($available)) {
      $build['message'] = [
        '#markup' => t('There was a problem getting update information. Try again later.'),
      ];
      return $build;
    }


    // This will be a nested array. The first key is the kind of project, which
    // can be either 'enabled', 'disabled', 'manual' (projects which require
    // manual updates, such as core). Then, each subarray is an array of
    // projects of that type, indexed by project short name, and containing an
    // array of data for cells in that project's row in the appropriate table.
    $projects = [];

    // This stores the actual download link we're going to update from for each
    // project in the build, regardless of if it's enabled or disabled.
    $build['project_downloads'] = ['#tree' => TRUE];
    module_load_include('inc', 'update', 'update.compare');

    $project_data = update_calculate_project_data($available);
    foreach ($project_data as $name => $project) {
      // Filter out projects which are up to date already.
      if ($project['status'] == UpdateManagerInterface::CURRENT) {
        continue;
      }
      // The project name to display can vary based on the info we have.
      if (!empty($project['title'])) {
        if (!empty($project['link'])) {
          $project_name = $this->l($project['title'], Url::fromUri($project['link']));
        }
        else {
          $project_name = $project['title'];
        }
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
      $recommended_version = '{{ release_version }} (<a href="{{ release_link }}" title="{{ project_title }}">{{ release_notes }}</a>)';
      if ($recommended_release['version_major'] != $project['existing_major']) {
        $recommended_version .= '<div title="{{ major_update_warning_title }}" class="update-major-version-warning">{{ major_update_warning_text }}</div>';
      }

      $recommended_version = [
        '#type' => 'inline_template',
        '#template' => $recommended_version,
        '#context' => [
          'release_version' => $recommended_release['version'],
          'release_link' => $recommended_release['release_link'],
          'project_title' => t('Release notes for @project_title', ['@project_title' => $project['title']]),
          'major_update_warning_title' => t('Major upgrade warning'),
          'major_update_warning_text' => t('This update is a major version update which means that it may not be backwards compatible with your currently running version. It is recommended that you read the release notes and proceed at your own risk.'),
          'release_notes' => t('Release notes'),
        ],
      ];

      // Create an entry for this project.
      $entry = [
        'title' => $project_name,
        'installed_version' => $project['existing_version'],
        'recommended_version' => ['data' => $recommended_version],
      ];

      switch ($project['status']) {
        case UpdateManagerInterface::NOT_SECURE:
        case UpdateManagerInterface::REVOKED:
          $entry['title'] .= ' ' . t('(Security update)');
          $entry['#weight'] = -2;
          $type = 'security';
          break;

        case UpdateManagerInterface::NOT_SUPPORTED:
          $type = 'unsupported';
          $entry['title'] .= ' ' . t('(Unsupported)');
          $entry['#weight'] = -1;
          break;

        case UpdateFetcherInterface::UNKNOWN:
        case UpdateFetcherInterface::NOT_FETCHED:
        case UpdateFetcherInterface::NOT_CHECKED:
        case UpdateManagerInterface::NOT_CURRENT:
          $type = 'recommended';
          break;

        default:
          // Jump out of the switch and onto the next project in foreach.
          continue 2;
      }

      // Use the project title for the table.
      $entry['title'] = [
        'data' => [
          '#title' => $entry['title'],
          '#markup' => $entry['title'],
        ],
      ];
      $entry['#attributes'] = ['class' => ['update-' . $type]];

      // Since the data formats are incompatible,
      // we convert now to the format expected by '#theme' => 'table'.
      unset($entry['#weight']);
      $attributes = $entry['#attributes'];
      unset($entry['#attributes']);
      $entry = [
          'data' => $entry,
        ] + $attributes;
      
      // Based on what kind of project this is, save the entry into the
      // appropriate subarray.
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

    $headers = [
      'title' => [
        'data' => t('Name'),
        'class' => ['update-project-name'],
      ],
      'installed_version' => t('Installed version'),
      'recommended_version' => t('Recommended version'),
    ];

    if (!empty($projects['enabled'])) {
      $build['projects'] = [
        '#type' => 'table',
        '#header' => $headers,
        '#rows' => $projects['enabled'],
      ];
      if (!empty($projects['disabled'])) {
        $build['projects']['#prefix'] = '<h2>' . t('Enabled') . '</h2>';
      }
    }

    if (!empty($projects['disabled'])) {
      $build['disabled_projects'] = [
        '#type' => 'table',
        '#header' => $headers,
        '#rows' => $projects['disabled'],
        '#weight' => 1,
        '#prefix' => '<h2>' . t('Disabled') . '</h2>',
      ];
    }

    if (!empty($projects['manual'])) {
      $prefix = '<h2>' . t('Manual updates required') . '</h2>';
      $build['manual_updates'] = [
        '#type' => 'table',
        '#header' => $headers,
        '#rows' => $projects['manual'],
        '#prefix' => $prefix,
        '#weight' => 120,
      ];
    }

    return $build;
  }

}
