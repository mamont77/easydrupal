<?php

declare(strict_types=1);

namespace Drupal\AiBestPractices\Composer;

use Drupal\AiBestPractices\Console\AiBestPracticesApplication;

/**
 * Pure composer.json edits applied when the package is removed from a consumer project.
 */
final class ComposerConsumerRollback
{
    /**
     * Removes our Composer script keys (`drupal-ai`, legacy `abp`), this package's
     * `config.allow-plugins` entry, and prunes empty `scripts`, `allow-plugins`, and `config` objects.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function stripPluginFromComposerData(array $data): array
    {
        if (isset($data['scripts']) && \is_array($data['scripts'])) {
            unset($data['scripts']['abp'], $data['scripts'][AiBestPracticesApplication::COMPOSER_SCRIPT_NAME]);
            if ([] === $data['scripts']) {
                unset($data['scripts']);
            }
        }

        if (!isset($data['config']) || !\is_array($data['config'])) {
            return $data;
        }

        if (isset($data['config']['allow-plugins']) && \is_array($data['config']['allow-plugins'])) {
            unset($data['config']['allow-plugins'][ComposerProjectSynchronizer::PACKAGE_NAME]);
            if ([] === $data['config']['allow-plugins']) {
                unset($data['config']['allow-plugins']);
            }
        }

        if ([] === $data['config']) {
            unset($data['config']);
        }

        return $data;
    }
}
