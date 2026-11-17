<?php

declare(strict_types=1);

namespace Drupal\AiBestPractices;

use Symfony\Component\Filesystem\Path;

/**
 * Canonical filesystem path to this Composer package (contains src/, .agents/, etc.).
 */
final class PackageRoot
{
    /**
     * Absolute canonical path to the installed drupal/ai_best_practices package root.
     */
    public static function path(): string
    {
        return Path::canonicalize(dirname(__DIR__, 2));
    }

    /**
     * True when the given project root is this package's own tree (not a consumer project).
     */
    public static function isPackageOwnTree(string $projectRoot): bool
    {
        $canonicalProject = Path::canonicalize($projectRoot);

        return $canonicalProject === self::path();
    }
}
