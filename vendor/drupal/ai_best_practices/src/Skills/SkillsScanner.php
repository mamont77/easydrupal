<?php

declare(strict_types=1);

namespace Drupal\AiBestPractices\Skills;

use Drupal\AiBestPractices\PathSecurity;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Service class for scanning and discovering skills from multiple sources.
 */
class SkillsScanner
{
    /**
     * The project root directory.
     */
    private string $projectRoot;
    /**
     * Skill configuration array.
     */
    private array $skillConfig;

    /**
     * SkillsScanner constructor.
     *
     * @param string $projectRoot
     *                            The project root directory
     */
    public function __construct(string $projectRoot)
    {
        $this->projectRoot = $projectRoot;
        $this->skillConfig = SkillsConfig::getSkillConfig($projectRoot);
    }

    /**
     * Scan for all skills from enabled sources.
     *
     * @param SymfonyStyle|SkillsOutputStyleInterface $io
     *                                                    The IO style helper (Symfony console or Composer plugin adapter)
     *
     * @return array
     *               Array of discovered skills keyed by skill name, with source info
     */
    public function scanAllSkills(SymfonyStyle|SkillsOutputStyleInterface $io): array
    {
        $skills = [];
        $scannedPaths = [];
        $excludedDirs = SkillsConfig::getExcludedDirectories($this->projectRoot);
        $outputPath = SkillsConfig::getOutputSkillsPath($this->projectRoot);

        // Scan project-level skills (highest priority).
        // Checks .agents/skills, .claude/skills, then skills (see SkillsConfig order).
        // Skip the output directory to avoid scanning skills we've already installed.
        $skillDirNames = SkillsConfig::getSkillDirectoryNames();
        foreach ($skillDirNames as $dirName) {
            $projectSkillsPath = PathSecurity::joinPaths($this->projectRoot, $dirName);

            // Skip if this is the output directory (where we install TO, not scan FROM)
            $canonicalProjectPath = PathSecurity::canonicalizePath($projectSkillsPath);
            $canonicalOutputPath = PathSecurity::canonicalizePath($outputPath);
            if ('' !== $canonicalProjectPath && '' !== $canonicalOutputPath
                  && $canonicalProjectPath === $canonicalOutputPath) {
                continue;
            }

            if (PathSecurity::isValidDirectory($projectSkillsPath, false)) {
                $projectSkills = $this->scanSkillsDirectory($projectSkillsPath, 'project');
                foreach ($projectSkills as $skillName => $skillInfo) {
                    // Earlier directory names in SkillsConfig take priority.
                    if (!isset($skills[$skillName])) {
                        $skills[$skillName] = $skillInfo;
                    }
                }
                if (!empty($projectSkills)) {
                    $scannedPaths[] = $projectSkillsPath;
                }
            }
        }

        // Scan recursively for composer packages and document-root extensions.
        if ($this->skillConfig['discover_composer'] || $this->skillConfig['discover_webroot']) {
            $this->scanRecursively(
                $this->projectRoot,
                '',
                $excludedDirs,
                $skills,
                $scannedPaths,
                $outputPath
            );
        }

        if (!empty($scannedPaths)) {
            $io->info('Scanned paths: '.implode(', ', $scannedPaths));
        }

        return $skills;
    }

    /**
     * Recursively scan directory tree for skill container directories.
     *
     * @param string $basePath
     *                             The base path to scan
     * @param string $relativePath
     *                             The relative path from project root
     * @param array  $excludedDirs
     *                             Array of directory names to exclude
     * @param array  $skills
     *                             Reference to skills array (will be modified)
     * @param array  $scannedPaths
     *                             Reference to scanned paths array (will be modified)
     * @param string $outputPath
     *                             The output directory path (to exclude from scanning)
     */
    private function scanRecursively(
        string $basePath,
        string $relativePath,
        array $excludedDirs,
        array &$skills,
        array &$scannedPaths,
        string $outputPath,
    ): void {
        // Validate base path is within project root.
        if (!PathSecurity::isPathWithinBase($basePath, $this->projectRoot)) {
            // Skip paths outside project root.
            return;
        }

        // Check for .agents/skills, .claude/skills, and skills under this directory.
        $skillDirNames = SkillsConfig::getSkillDirectoryNames();
        $foundSkillsInLocation = [];

        foreach ($skillDirNames as $dirName) {
            $skillsPath = PathSecurity::joinPaths($basePath, $dirName);
            if (PathSecurity::isValidDirectory($skillsPath, false)) {
                // Determine source type.
                $isVendorPath = PathSecurity::isRelativePathUnderPrefix($relativePath, 'vendor/');

                $webRootPrefix = $this->skillConfig['web_root_prefix'] ?? null;
                $isWebRootPath = is_string($webRootPrefix)
                    && '' !== $webRootPrefix
                    && !$isVendorPath
                    && PathSecurity::isRelativePathUnderPrefix($relativePath, $webRootPrefix);

                $sourceType = 'unknown';
                $sourceName = '';

                if ($isVendorPath && $this->skillConfig['discover_composer']) {
                    $sourceType = 'composer';
                    // Extract package name from vendor path.
                    $pathParts = explode('/', trim($relativePath, '/'));
                    if (count($pathParts) >= 3 && 'vendor' === $pathParts[0]) {
                        $sourceName = $pathParts[1].'/'.$pathParts[2];
                    } else {
                        $sourceName = $relativePath ?: 'vendor';
                    }
                    $whitelist = $this->skillConfig['composer_package_whitelist'] ?? [];
                    if ([] !== $whitelist && '' !== $sourceName && !in_array($sourceName, $whitelist, true)) {
                        continue;
                    }
                } elseif ($isWebRootPath && $this->skillConfig['discover_webroot']) {
                    $sourceType = 'webroot';
                    $sourceName = basename($basePath);
                }

                // Only scan if source type is enabled.
                if ('unknown' !== $sourceType) {
                    $foundSkills = $this->scanSkillsDirectory($skillsPath, $sourceType, $sourceName);

                    // Filter skills based on include/exclude configuration.
                    $filteredSkills = $this->filterSkills($foundSkills, $sourceName, $sourceType);

                    // Merge skills; directory order in SkillsConfig defines priority.
                    foreach ($filteredSkills as $skillName => $skillInfo) {
                        // Only add if not already present from higher priority directory.
                        if (!isset($foundSkillsInLocation[$skillName])) {
                            $foundSkillsInLocation[$skillName] = $skillInfo;
                        }
                    }

                    if (!empty($filteredSkills)) {
                        $scannedPaths[] = $skillsPath;
                    }
                }
            }
        }

        // Add all found skills, but don't override project-level skills.
        foreach ($foundSkillsInLocation as $skillName => $skillInfo) {
            // Only add if not already present (project-level takes priority)
            if (!isset($skills[$skillName])) {
                $skills[$skillName] = $skillInfo;
            }
        }

        // Recursively scan subdirectories.
        $items = scandir($basePath);
        if (false === $items) {
            return;
        }

        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }

            // Skip excluded directories, but allow 'ai_best_practices' inside vendor packages.
            $isVendorPath = PathSecurity::isRelativePathUnderPrefix($relativePath, 'vendor/');

            // Don't exclude package directories matching this project when scanning vendor packages.
            if (in_array($item, $excludedDirs) && !('ai_best_practices' === $item && $isVendorPath)) {
                continue;
            }

            // Skip nested package dirs and .claude dirs to avoid infinite recursion;
            // allow scanning inside vendor-installed packages (e.g. drupal/ai_best_practices).
            if ('ai_best_practices' === $item) {
                if (!$isVendorPath && (false !== strpos($relativePath, 'ai_best_practices/') || false !== strpos($basePath, '/ai_best_practices/'))) {
                    continue;
                }
            }
            if ('.claude' === $item) {
                // Only skip the project root .claude directory (output directory)
                // Allow scanning .claude directories in vendor packages and modules.
                $claudePath = PathSecurity::joinPaths($basePath, '.claude');
                $canonicalClaudePath = PathSecurity::canonicalizePath($claudePath);
                $canonicalOutputPath = PathSecurity::canonicalizePath($outputPath);

                // Skip only if this is the project root output directory.
                if ('' !== $canonicalClaudePath && '' !== $canonicalOutputPath
                      && $canonicalClaudePath === $canonicalOutputPath) {
                    continue;
                }
                // Otherwise, allow scanning .claude directories in vendor/modules.
            }

            // Sanitize directory name to prevent path traversal.
            $sanitizedItem = PathSecurity::sanitizePathComponent($item);
            if ('' === $sanitizedItem || $sanitizedItem !== $item) {
                // Skip invalid directory names.
                continue;
            }

            $fullPath = PathSecurity::joinPaths($basePath, $sanitizedItem);

            // Validate directory is within project root and not a symlink.
            if (!PathSecurity::isValidDirectory($fullPath, false)) {
                continue;
            }

            // Ensure path stays within project root.
            if (!PathSecurity::isPathWithinBase($fullPath, $this->projectRoot)) {
                // Skip paths outside project root.
                continue;
            }

            $newRelativePath = $relativePath ? PathSecurity::joinPaths($relativePath, $sanitizedItem) : $sanitizedItem;
            $this->scanRecursively(
                $fullPath,
                $newRelativePath,
                $excludedDirs,
                $skills,
                $scannedPaths,
                $outputPath
            );
        }
    }

    /**
     * Scan a skills directory for individual skill subdirectories.
     *
     * @param string $skillsPath
     *                           Path to a skills container (e.g. .agents/skills, .claude/skills, or skills).
     * @param string $sourceType
     *                           Source type ('project', 'composer', 'webroot')
     * @param string $sourceName
     *                           Name of the source (package name, module name, etc.).
     *
     * @return array
     *               Array of skills keyed by skill name
     */
    private function scanSkillsDirectory(string $skillsPath, string $sourceType, string $sourceName = ''): array
    {
        $skills = [];

        // Validate skills path is within project root.
        if (!PathSecurity::isValidDirectory($skillsPath, false)) {
            return $skills;
        }

        if (!PathSecurity::isPathWithinBase($skillsPath, $this->projectRoot)) {
            // Skip paths outside project root.
            return $skills;
        }

        $items = scandir($skillsPath);
        if (false === $items) {
            return $skills;
        }

        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }

            // Sanitize directory name to prevent path traversal.
            $sanitizedItem = PathSecurity::sanitizePathComponent($item);
            if ('' === $sanitizedItem || $sanitizedItem !== $item) {
                // Skip invalid directory names.
                continue;
            }

            $skillPath = PathSecurity::joinPaths($skillsPath, $sanitizedItem);

            // Validate directory is within project root and not a symlink.
            if (!PathSecurity::isValidDirectory($skillPath, false)) {
                continue;
            }

            // Ensure path stays within project root.
            if (!PathSecurity::isPathWithinBase($skillPath, $this->projectRoot)) {
                // Skip paths outside project root.
                continue;
            }

            // Check if it's a valid skill (has SKILL.md)
            $skillMdPath = PathSecurity::joinPaths($skillPath, SkillsConfig::SKILL_MD_FILENAME);
            $filesystem = new Filesystem();
            if (!$filesystem->exists($skillMdPath)) {
                continue;
            }

            // Use sanitized directory name as skill name.
            $skillName = $sanitizedItem;

            $skills[$skillName] = [
                'path' => $skillPath,
                'name' => $skillName,
                'source_type' => $sourceType,
                'source_name' => $sourceName ?: $sourceType,
            ];
        }

        return $skills;
    }

    /**
     * Filter skills based on exclude configuration.
     *
     * @param array  $skills
     *                           Array of skills to filter
     * @param string $sourceName
     *                           Name of the source (package name, module name, etc.).
     * @param string $sourceType
     *                           Type of source ('composer', 'webroot')
     *
     * @return array
     *               Filtered skills array
     */
    private function filterSkills(array $skills, string $sourceName, string $sourceType): array
    {
        $excludeSkills = $this->skillConfig['exclude_skills'] ?? [];

        // If no exclusions configured, return all skills.
        if (empty($excludeSkills)) {
            return $skills;
        }

        $filtered = [];

        foreach ($skills as $skillName => $skillInfo) {
            $skillIdentifier = $sourceName.':'.$skillName;

            // Skip if in exclude list.
            if (in_array($skillIdentifier, $excludeSkills)) {
                continue;
            }

            $filtered[$skillName] = $skillInfo;
        }

        return $filtered;
    }
}
