<?php

declare(strict_types=1);

namespace Drupal\AiBestPractices\Config;

use Drupal\AiBestPractices\PathSecurity;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads and merges project-level configuration from ai_best_practices.yaml.
 */
class AiBestPracticesConfigLoader
{
    /**
     * Per-request cache of loaded config keyed by canonical project root.
     *
     * @var array<string, array<string, mixed>>
     */
    private static array $configCache = [];

    /**
     * Default configuration values.
     */
    private const DEFAULT_CONFIG = [
        'auto_register_composer_scripts' => true,
        'excluded_directories' => [
            '.git',
            'node_modules',
            '.idea',
        ],
        'skills' => [
            'enabled' => true,
            'discover_composer' => true,
            'discover_modules' => true,
            'web_root' => null,
            'output_directory' => '.agents/skills',
            'excluded_directories' => [],
            'exclude_skills' => [],
            'composer_package_whitelist' => [],
        ],
        'agents' => [
            'enabled' => true,
            'environment' => 'auto',
            'sections' => [],
            'overrides' => [],
        ],
    ];

    /**
     * Load configuration from project's ai_best_practices.yaml file.
     *
     * Returns defaults when the config file is absent, or when it exists but is
     * empty / whitespace-only (a syntactically valid YAML document that is
     * semantically absent — parses to null). Throws \InvalidArgumentException
     * only when the file is genuinely invalid: unreadable, malformed YAML, or
     * well-formed-but-wrong-shape (a scalar or sequence where a mapping is
     * required). Callers on the write path must catch this and abort rather
     * than silently proceeding under wrong assumptions.
     *
     * @param string $projectRoot
     *                            The project root directory
     *
     * @return array
     *               Merged configuration array
     *
     * @throws \InvalidArgumentException
     *                                   When the config file is unreadable or contains invalid YAML
     */
    public static function load(string $projectRoot): array
    {
        $cacheKey = PathSecurity::canonicalizePath($projectRoot);
        if ('' === $cacheKey) {
            $cacheKey = $projectRoot;
        }

        if (isset(self::$configCache[$cacheKey])) {
            return self::$configCache[$cacheKey];
        }

        $configFile = PathSecurity::joinPaths($projectRoot, 'ai_best_practices.yaml');
        $filesystem = new Filesystem();

        if (!$filesystem->exists($configFile)) {
            self::$configCache[$cacheKey] = self::DEFAULT_CONFIG;

            return self::$configCache[$cacheKey];
        }

        $yamlContent = file_get_contents($configFile);
        if (false === $yamlContent) {
            throw new \InvalidArgumentException(sprintf('Could not read ai_best_practices.yaml at %s.', $configFile));
        }

        try {
            $config = Yaml::parse($yamlContent);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException(sprintf('ai_best_practices.yaml at %s contains malformed YAML: %s', $configFile, $e->getMessage()), 0, $e);
        }

        // An empty or whitespace-only file is valid YAML that parses to null. It is
        // semantically absent, so it follows the same path as a missing file: defaults.
        if (null === $config) {
            self::$configCache[$cacheKey] = self::DEFAULT_CONFIG;

            return self::$configCache[$cacheKey];
        }

        // A YAML sequence parses to a PHP list-array; only a YAML mapping (or empty mapping {})
        // is valid config. An empty array from {} has no keys so array_is_list([]) is true,
        // but we accept it because it is a valid empty mapping that merges cleanly to defaults.
        if (!is_array($config) || ([] !== $config && array_is_list($config))) {
            throw new \InvalidArgumentException(sprintf('ai_best_practices.yaml at %s must be a YAML mapping (got %s). Fix the file before running sync.', $configFile, is_array($config) ? 'sequence' : gettype($config)));
        }

        self::$configCache[$cacheKey] = self::mergeConfig(self::DEFAULT_CONFIG, $config);

        return self::$configCache[$cacheKey];
    }

    /**
     * Clear the in-memory config cache (for tests).
     */
    public static function clearCache(): void
    {
        self::$configCache = [];
    }

    /**
     * Recursively merge configuration arrays.
     *
     * @param array $defaults
     *                        Default configuration
     * @param array $config
     *                        User configuration
     *
     * @return array
     *               Merged configuration
     */
    private static function mergeConfig(array $defaults, array $config): array
    {
        $merged = $defaults;

        foreach ($config as $key => $value) {
            if (isset($merged[$key]) && is_array($merged[$key]) && is_array($value)) {
                $merged[$key] = self::mergeConfig($merged[$key], $value);
            } else {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    /**
     * Get default configuration.
     *
     * @return array
     *               Default configuration array
     */
    public static function getDefaultConfig(): array
    {
        return self::DEFAULT_CONFIG;
    }
}
