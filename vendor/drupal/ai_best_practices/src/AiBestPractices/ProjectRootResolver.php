<?php

declare(strict_types=1);

namespace Drupal\AiBestPractices;

use Symfony\Component\Filesystem\Path;

/**
 * Resolves the Composer project root by locating composer.json upward from a start directory.
 */
final class ProjectRootResolver
{
    /**
     * Maximum parent directories to walk when searching for composer.json.
     */
    public const MAX_DEPTH = 20;

    /**
     * Walk upward from the start directory until composer.json is found.
     *
     * @param string|null $startDir
     *                              Directory to begin from; defaults to getcwd()
     *
     * @return string
     *                Canonical absolute path to the directory containing composer.json.
     *
     * @throws \RuntimeException
     *                           When composer.json cannot be found or the path is invalid.
     */
    public static function resolve(?string $startDir = null): string
    {
        $dir = $startDir ?? getcwd();
        if (false === $dir || '' === $dir) {
            throw new \RuntimeException('Could not determine project root: invalid working directory.');
        }

        $dir = Path::canonicalize($dir);

        for ($i = 0; $i < self::MAX_DEPTH; ++$i) {
            $composerJson = Path::join($dir, 'composer.json');
            if (is_file($composerJson)) {
                $canonical = PathSecurity::canonicalizePath($dir);
                if ('' === $canonical) {
                    throw new \RuntimeException('Could not determine project root: path could not be resolved.');
                }

                return $canonical;
            }

            $parent = dirname($dir);
            if ($parent === $dir || '' === $parent) {
                break;
            }
            $dir = $parent;
        }

        throw new \RuntimeException('Could not find composer.json in the current directory or any parent directory (within '.self::MAX_DEPTH.' levels). Run this command from your Composer project root.');
    }
}
