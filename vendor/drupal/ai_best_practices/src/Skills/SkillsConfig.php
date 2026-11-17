<?php

declare(strict_types=1);

namespace Drupal\AiBestPractices\Skills;

use Drupal\AiBestPractices\Config\AiBestPracticesConfigLoader;
use Drupal\AiBestPractices\PathSecurity;
use Symfony\Component\Filesystem\Path;

/**
 * Configuration class for Skills module paths and settings.
 *
 * Centralizes all directory paths and configuration to allow easy modification.
 */
class SkillsConfig
{
    /**
     * Project-level skills directory name.
     */
    public const PROJECT_SKILLS_DIR = 'skills';

    /**
     * Third-party package skills directory name.
     */
    public const THIRD_PARTY_SKILLS_DIR = 'skills';

    /**
     * Agent Skills convention directory (see agentskills/agentskills#15).
     */
    public const AGENTS_SKILLS_DIR = '.agents/skills';

    /**
     * Alternate skills directory (e.g. Claude Code convention).
     */
    public const CLAUDE_SKILLS_DIR = '.claude/skills';

    /**
     * Default output directory for aggregated skills (configurable via ai_best_practices.yaml).
     */
    public const OUTPUT_SKILLS_DIR = '.agents/skills';

    /**
     * Required SKILL.md filename.
     */
    public const SKILL_MD_FILENAME = 'SKILL.md';

    /**
     * Get the project skills directory path.
     *
     * @param string $projectRoot
     *                            The project root directory
     *
     * @return string
     *                The full path to project skills directory
     */
    public static function getProjectSkillsPath(string $projectRoot): string
    {
        return $projectRoot.'/'.self::PROJECT_SKILLS_DIR;
    }

    /**
     * Get the third-party skills directory name.
     *
     * @return string
     *                The directory name for third-party skills
     */
    public static function getThirdPartySkillsDir(): string
    {
        return self::THIRD_PARTY_SKILLS_DIR;
    }

    /**
     * Get all skill directory names to scan (in priority order).
     *
     * @return array
     *               Array of directory names, ordered by priority (highest first)
     */
    public static function getSkillDirectoryNames(): array
    {
        return [
            self::AGENTS_SKILLS_DIR,
            self::CLAUDE_SKILLS_DIR,
            self::THIRD_PARTY_SKILLS_DIR,
        ];
    }

    /**
     * Get the output skills directory path.
     *
     * @param string $projectRoot
     *                            The project root directory
     *
     * @return string
     *                The full path to output skills directory
     */
    public static function getOutputSkillsPath(string $projectRoot): string
    {
        $config = AiBestPracticesConfigLoader::load($projectRoot);
        $outputDir = $config['skills']['output_directory'] ?? self::OUTPUT_SKILLS_DIR;

        return self::resolveValidatedSkillsOutputPath($projectRoot, $outputDir);
    }

    /**
     * Picks the output directory to write into a new ai_best_practices.yaml.
     *
     * Claude Code loads project skills only from .claude/skills. Detection runs
     * once, when the yaml is created, so the output directory does not change
     * depending on who runs Composer later.
     */
    public static function detectDefaultOutputDirectory(string $projectRoot): string
    {
        return self::isClaudeCodeProject($projectRoot) ? self::CLAUDE_SKILLS_DIR : self::OUTPUT_SKILLS_DIR;
    }

    private static function isClaudeCodeProject(string $projectRoot): bool
    {
        if (false !== getenv('CLAUDECODE') || false !== getenv('CLAUDE_CODE')) {
            return true;
        }

        // Container wrappers (older DDEV releases, Lando, docker compose exec)
        // may not forward CLAUDECODE from the host.
        return is_dir(PathSecurity::joinPaths($projectRoot, '.claude'));
    }

    /**
     * Validates skills output_directory and returns the absolute path under project root.
     *
     * @param string $projectRoot
     *                            Project root directory
     * @param mixed  $outputDir
     *                            Configured relative path (e.g. ".agents/skills").
     *
     * @return string
     *                Canonical absolute path to the output skills directory
     *
     * @throws \InvalidArgumentException
     *                                   When the path escapes the project root or is otherwise invalid
     */
    public static function resolveValidatedSkillsOutputPath(string $projectRoot, mixed $outputDir): string
    {
        if (!is_string($outputDir) || '' === $outputDir) {
            $outputDir = self::OUTPUT_SKILLS_DIR;
        }
        if (str_contains($outputDir, "\0")) {
            throw new \InvalidArgumentException('skills.output_directory contains invalid characters.');
        }
        if (Path::isAbsolute($outputDir)) {
            throw new \InvalidArgumentException('skills.output_directory must be a relative path.');
        }

        $resolved = self::resolveValidatedRelativePath($projectRoot, $outputDir, false);
        if (null === $resolved) {
            throw new \InvalidArgumentException('skills.output_directory must resolve inside the project root. Check ai_best_practices.yaml.');
        }

        return $resolved;
    }

    /**
     * Validates a relative path resolves inside the project root.
     *
     * @param string $projectRoot
     *                                         Project root directory
     * @param string $relativePath
     *                                         Relative path from project root (e.g. "web/", ".agents/skills")
     * @param bool   $requireExistingDirectory
     *                                         When true, the resolved path must exist as a directory
     *
     * @return string|null
     *                     Canonical absolute path, or null when invalid
     */
    public static function resolveValidatedRelativePath(
        string $projectRoot,
        string $relativePath,
        bool $requireExistingDirectory = false,
    ): ?string {
        if (str_contains($relativePath, "\0")) {
            return null;
        }
        if (Path::isAbsolute($relativePath)) {
            return null;
        }

        $canonicalRoot = PathSecurity::canonicalizePath($projectRoot);
        if ('' === $canonicalRoot) {
            return null;
        }

        $joined = Path::join($canonicalRoot, $relativePath);
        $normalized = Path::canonicalize($joined);
        $normalizedBase = Path::canonicalize($canonicalRoot);

        if (!Path::isBasePath($normalizedBase, $normalized)) {
            return null;
        }

        if ($requireExistingDirectory && !is_dir($normalized)) {
            return null;
        }

        return $normalized;
    }

    /**
     * Get the output skills directory name (relative to project root).
     *
     * @param string $projectRoot
     *                            The project root directory
     *
     * @return string
     *                The directory name relative to project root
     */
    public static function getOutputSkillsDirectory(string $projectRoot): string
    {
        $config = AiBestPracticesConfigLoader::load($projectRoot);

        return $config['skills']['output_directory'] ?? self::OUTPUT_SKILLS_DIR;
    }

    /**
     * Get directories excluded from project scanning.
     *
     * @param string $projectRoot
     *                            The project root directory
     *
     * @return array
     *               Array of directory names to exclude
     */
    public static function getExcludedDirectories(string $projectRoot): array
    {
        $config = AiBestPracticesConfigLoader::load($projectRoot);

        // Get default excluded directories.
        $defaults = AiBestPracticesConfigLoader::getDefaultConfig();
        $excluded = $defaults['excluded_directories'] ?? [];

        // Add skills-specific excluded directories.
        if (isset($config['skills']['excluded_directories'])) {
            $excluded = array_merge($excluded, $config['skills']['excluded_directories']);
        }

        // Also check top-level excluded_directories.
        if (isset($config['excluded_directories'])) {
            $excluded = array_merge($excluded, $config['excluded_directories']);
        }

        // Remove duplicates.
        $excluded = array_unique($excluded);

        // Always exclude the output directory to avoid recursion.
        $outputDir = self::getOutputSkillsDirectory($projectRoot);
        // Extract the top-level directory name (e.g., '.agents/skills' -> '.agents').
        $outputDirParts = explode('/', $outputDir);
        $topLevelDir = $outputDirParts[0];
        if ('' !== $topLevelDir && !in_array($topLevelDir, $excluded)) {
            $excluded[] = $topLevelDir;
        }

        return $excluded;
    }

    /**
     * Get skill configuration from project config.
     *
     * discover_webroot is canonical; discover_modules is a deprecated alias used only
     * when discover_webroot is absent from the merged config.
     *
     * @param string $projectRoot
     *                            The project root directory
     *
     * @return array
     *               Skill configuration array with enabled sources and web_root_prefix
     */
    public static function getSkillConfig(string $projectRoot): array
    {
        $config = AiBestPracticesConfigLoader::load($projectRoot);

        $skills = $config['skills'] ?? [];

        return [
            'enabled' => $skills['enabled'] ?? true,
            'discover_composer' => $skills['discover_composer'] ?? true,
            'discover_webroot' => array_key_exists('discover_webroot', $skills)
                ? (bool) $skills['discover_webroot']
                : (bool) ($skills['discover_modules'] ?? true),
            'discover_modules' => $skills['discover_modules'] ?? true,
            'web_root' => $skills['web_root'] ?? null,
            'web_root_prefix' => WebRootResolver::resolve($projectRoot, $config),
            'exclude_skills' => $skills['exclude_skills'] ?? [],
            'composer_package_whitelist' => $skills['composer_package_whitelist'] ?? [],
        ];
    }

    /**
     * Check if skills are enabled.
     *
     * @param string $projectRoot
     *                            The project root directory
     *
     * @return bool
     *              True if skills are enabled (defaults to true)
     */
    public static function isEnabled(string $projectRoot): bool
    {
        $skillConfig = self::getSkillConfig($projectRoot);

        return $skillConfig['enabled'] ?? true;
    }
}
