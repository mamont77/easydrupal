<?php

declare(strict_types=1);

namespace Drupal\AiBestPractices\Skills;

use Drupal\AiBestPractices\Config\AiBestPracticesConfigLoader;
use Drupal\AiBestPractices\PathSecurity;

/**
 * Resolves the Drupal document root relative prefix from project configuration.
 */
final class WebRootResolver
{
    /**
     * Drupal extension directory markers used to infer docroot from installer-paths.
     */
    private const EXTENSION_MARKERS = [
        'modules',
        'themes',
        'profiles',
        'core',
        'libraries',
    ];

    /**
     * Filesystem fallback directory names to probe when composer.json has no signal.
     */
    private const FALLBACK_DIRS = [
        'web',
        'html',
        'docroot',
    ];

    /**
     * Resolve the document root relative prefix (e.g. "web/") from the project root.
     *
     * @param string                    $projectRoot
     *                                               The project root directory
     * @param array<string, mixed>|null $config
     *                                               Pre-loaded project config; loaded when null
     *
     * @return string|null
     *                     Normalized relative prefix with trailing slash, or null when unknown
     */
    public static function resolve(string $projectRoot, ?array $config = null): ?string
    {
        $config ??= AiBestPracticesConfigLoader::load($projectRoot);
        $configured = $config['skills']['web_root'] ?? null;
        if (is_string($configured) && '' !== $configured) {
            $validated = self::validateWebRootPrefix($projectRoot, $configured);
            if (null !== $validated) {
                return $validated;
            }
        }

        $composerJson = self::readComposerJson($projectRoot);
        if (null !== $composerJson) {
            $fromScaffold = self::fromDrupalScaffold($composerJson, $projectRoot);
            if (null !== $fromScaffold) {
                return $fromScaffold;
            }

            $fromInstallerPaths = self::fromInstallerPaths($composerJson);
            if (null !== $fromInstallerPaths) {
                $validated = self::validateWebRootPrefix($projectRoot, $fromInstallerPaths);
                if (null !== $validated) {
                    return $validated;
                }
            }
        }

        return self::fromFilesystemFallback($projectRoot);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function readComposerJson(string $projectRoot): ?array
    {
        $composerFile = PathSecurity::joinPaths($projectRoot, 'composer.json');
        if (!is_file($composerFile)) {
            return null;
        }

        $content = file_get_contents($composerFile);
        if (false === $content) {
            return null;
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $composerJson
     */
    private static function fromDrupalScaffold(array $composerJson, string $projectRoot): ?string
    {
        $webRoot = $composerJson['extra']['drupal-scaffold']['locations']['web-root'] ?? null;
        if (!is_string($webRoot) || '' === $webRoot) {
            return null;
        }

        return self::validateWebRootPrefix($projectRoot, $webRoot);
    }

    /**
     * @param array<string, mixed> $composerJson
     */
    private static function fromInstallerPaths(array $composerJson): ?string
    {
        $installerPaths = $composerJson['extra']['installer-paths'] ?? null;
        if (!is_array($installerPaths)) {
            return null;
        }

        $prefixes = [];
        foreach ($installerPaths as $pathTemplate => $constraints) {
            if (!is_string($pathTemplate) || !is_array($constraints)) {
                continue;
            }

            $hasDrupalType = false;
            foreach ($constraints as $constraint) {
                if (is_string($constraint) && str_starts_with($constraint, 'type:drupal-')) {
                    $hasDrupalType = true;
                    break;
                }
            }
            if (!$hasDrupalType) {
                continue;
            }

            $prefix = self::extractPrefixFromInstallerPath($pathTemplate);
            if (null !== $prefix) {
                $prefixes[] = $prefix;
            }
        }

        if ([] === $prefixes) {
            return null;
        }

        // Most common inferred prefix wins (typical layouts agree on one docroot).
        $counts = array_count_values($prefixes);
        arsort($counts);

        return array_key_first($counts);
    }

    private static function extractPrefixFromInstallerPath(string $pathTemplate): ?string
    {
        $normalized = str_replace('\\', '/', $pathTemplate);
        $normalized = trim($normalized, '/');
        if ('' === $normalized) {
            return null;
        }

        $parts = explode('/', $normalized);
        foreach ($parts as $i => $part) {
            if (in_array($part, self::EXTENSION_MARKERS, true) && $i > 0) {
                return self::normalizePrefix(implode('/', array_slice($parts, 0, $i)));
            }
        }

        return null;
    }

    private static function fromFilesystemFallback(string $projectRoot): ?string
    {
        foreach (self::FALLBACK_DIRS as $dir) {
            $validated = self::validateWebRootPrefix($projectRoot, $dir);
            if (null !== $validated) {
                return $validated;
            }
        }

        return null;
    }

    private static function normalizePrefix(string $prefix): string
    {
        $normalized = str_replace('\\', '/', trim($prefix));
        $normalized = trim($normalized, '/');
        if ('' === $normalized) {
            return '';
        }

        return $normalized.'/';
    }

    private static function validateWebRootPrefix(string $projectRoot, string $webRoot): ?string
    {
        if (null === SkillsConfig::resolveValidatedRelativePath($projectRoot, $webRoot, true)) {
            return null;
        }

        return self::normalizePrefix($webRoot);
    }
}
