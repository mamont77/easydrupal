<?php

declare(strict_types=1);

namespace Drupal\advupdate\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\update\UpdateManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a block listing modules with pending security updates.
 */
#[Block(
  id: 'advupdate_security_updates',
  admin_label: new TranslatableMarkup('Security Updates'),
  category: new TranslatableMarkup('Administration'),
)]
class SecurityUpdatesBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly UpdateManagerInterface $updateManager,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('update.manager'),
      $container->get('module_handler'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account): AccessResultInterface {
    $result = AccessResult::allowedIfHasPermission($account, 'administer site configuration');
    if ($result->isAllowed() && empty($this->getSecurityProjects())) {
      return AccessResult::forbidden()->addCacheTags(['update']);
    }
    return $result->addCacheTags(['update']);
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $projects = $this->getSecurityProjects();

    if (empty($projects)) {
      return [];
    }

    $rows = [];
    $hasCoreUpdates = FALSE;
    $hasContribUpdates = FALSE;

    foreach ($projects as $project) {
      $name = $project['info']['name'] ?? $project['name'];
      // Display "Drupal core" for core projects.
      if ($this->isCoreProject($project)) {
        $name = $this->t('Drupal core');
        $hasCoreUpdates = TRUE;
      }
      else {
        $hasContribUpdates = TRUE;
      }
      $current = $project['existing_version'] ?? $this->t('Unknown');
      $recommended = $project['recommended'] ?? ($project['latest_version'] ?? $this->t('Unknown'));
      $link = $project['link'] ?? "https://www.drupal.org/project/{$project['name']}";

      $rows[] = [
        'data' => [
          ['data' => ['#markup' => "<a href=\"{$link}\" target=\"_blank\" rel=\"noopener noreferrer\">{$name}</a>"]],
          ['data' => $current],
          ['data' => $recommended],
        ],
      ];
    }

    // Determine caption message based on update types.
    if ($hasCoreUpdates && !$hasContribUpdates) {
      $main_message = $this->t('There is a security update available for your version of Drupal. To ensure the security of your server, you should update immediately!');
    }
    else {
      $main_message = $this->t('There are security updates available for one or more of your modules or themes. To ensure the security of your server, you should update immediately!');
    }

    // Build caption with link to available updates page.
    $caption = [
      ['#markup' => $main_message],
      [
        '#prefix' => ' ',
        '#markup' => $this->t('See the <a href=":available_updates">available updates</a> page for more information.', [
          ':available_updates' => Url::fromRoute('update.status')->toString(),
        ]),
      ],
    ];

    return [
      '#theme' => 'table',
      '#header' => [
        $this->t('Module'),
        $this->t('Installed version'),
        $this->t('Recommended version'),
      ],
      '#caption' => $caption,
      '#rows' => $rows,
      '#attributes' => ['class' => ['advupdate-security-table']],
      '#cache' => [
        'max-age' => 3600,
        'tags' => ['update'],
      ],
    ];
  }

  /**
   * Returns projects that have security updates pending.
   *
   * @return array<string, array<string, mixed>>
   */
  private function getSecurityProjects(): array {
    // Try cached project data first.
    $project_data = $this->updateManager->projectStorage('update_project_data');

    if (empty($project_data)) {
      // Fall back to a fresh calculation if the cache is cold.
      if ($available = update_get_available()) {
        $this->moduleHandler->loadInclude('update', 'compare.inc');
        $project_data = update_calculate_project_data($available);
      }
    }

    if (empty($project_data)) {
      return [];
    }

    return array_filter(
      $project_data,
      static fn(array $p): bool => $p['status'] === UpdateManagerInterface::NOT_SECURE,
    );
  }

  /**
   * Checks if a project is part of Drupal core.
   *
   * @param array<string, mixed> $project
   *   The project data.
   *
   * @return bool
   *   TRUE if the project is part of Drupal core.
   */
  private function isCoreProject(array $project): bool {
    // Check if project name is 'drupal' (the core project itself).
    if (($project['name'] ?? '') === 'drupal') {
      return TRUE;
    }

    // Check if the project has a core indicator.
    if (!empty($project['core']) || ($project['project_type'] ?? '') === 'core') {
      return TRUE;
    }

    return FALSE;
  }

}
