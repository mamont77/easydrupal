<?php

declare(strict_types=1);

namespace Drupal\AiBestPractices\Skills;

use Drupal\AiBestPractices\PathSecurity;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Aggregates skills from all sources into the configured output directory.
 */
class SkillsAggregator
{
    /**
     * The project root directory.
     */
    private string $projectRoot;
    /**
     * The skills scanner instance.
     */
    private SkillsScanner $scanner;
    /**
     * The skill validator instance.
     */
    private SkillValidator $validator;
    /**
     * Skill configuration array.
     */
    private array $skillConfig;

    /**
     * SkillsAggregator constructor.
     *
     * @param string $projectRoot
     *                            The project root directory
     */
    public function __construct(string $projectRoot)
    {
        $this->projectRoot = $projectRoot;
        $this->scanner = new SkillsScanner($projectRoot);
        $this->validator = new SkillValidator();
        $this->skillConfig = SkillsConfig::getSkillConfig($projectRoot);
    }

    /**
     * Aggregate all skills and copy to output directory.
     *
     * @param SymfonyStyle|SkillsOutputStyleInterface $io
     *                                                    The IO style helper (Symfony console or Composer plugin adapter)
     *
     * @return array
     *               Array with 'success' => bool, 'installed' => int, 'errors' => array
     */
    public function aggregate(SymfonyStyle|SkillsOutputStyleInterface $io): array
    {
        $outputPath = SkillsConfig::getOutputSkillsPath($this->projectRoot);
        $installed = 0;
        $errors = [];
        $allSkills = [];

        // Scan skills from recursive discovery (modules, composer packages, project)
        $scannedSkills = $this->scanner->scanAllSkills($io);
        foreach ($scannedSkills as $skillName => $skillInfo) {
            $allSkills[$skillName] = $skillInfo;
        }

        if (empty($allSkills)) {
            $io->warning('No skills found to install.');

            return [
                'success' => true,
                'installed' => 0,
                'errors' => [],
            ];
        }

        // Create output directory using Symfony Filesystem.
        $filesystem = new Filesystem();
        try {
            $filesystem->mkdir($outputPath, 0755);
        } catch (IOExceptionInterface $e) {
            $errors[] = 'Failed to create output directory: '.$outputPath;

            return [
                'success' => false,
                'installed' => 0,
                'errors' => $errors,
            ];
        }

        // Remove skills that we're about to reinstall from sources (managed skills)
        // Only remove skills that ARE in the sources list - we're about to reinstall them
        // Skills not in sources list are unmanaged (manually added) and should be preserved.
        $canonicalOutput = PathSecurity::canonicalizePath($outputPath);
        if ('' !== $canonicalOutput && PathSecurity::isValidDirectory($canonicalOutput, false)) {
            foreach ($allSkills as $skillName => $skillInfo) {
                // Sanitize skill name.
                $sanitizedSkillName = PathSecurity::sanitizePathComponent($skillName);
                if ('' === $sanitizedSkillName || $sanitizedSkillName !== $skillName) {
                    // Skip invalid names.
                    continue;
                }

                $installedSkillPath = PathSecurity::joinPaths($canonicalOutput, $sanitizedSkillName);

                // Skip if not a directory or path escapes output directory.
                if (!PathSecurity::isValidDirectory($installedSkillPath, false)) {
                    continue;
                }
                if (!PathSecurity::isPathWithinBase($installedSkillPath, $canonicalOutput)) {
                    continue;
                }

                // Remove skill if it exists and we're about to reinstall it from sources
                // This ensures we get a fresh copy from the source.
                if ($filesystem->exists($installedSkillPath)) {
                    if ($this->removeDirectory($installedSkillPath, $filesystem)) {
                        $io->writeln("  ↻ Updating: {$sanitizedSkillName} (from {$skillInfo['source_type']})");
                    }
                }
            }
        }

        // Validate and copy each skill.
        foreach ($allSkills as $skillName => $skillInfo) {
            // Sanitize skill name to prevent path traversal.
            $sanitizedSkillName = PathSecurity::sanitizePathComponent($skillName);
            if ('' === $sanitizedSkillName || $sanitizedSkillName !== $skillName) {
                $errorMsg = "Invalid skill name (contains path traversal): {$skillName}";
                $errors[] = $errorMsg;
                $io->warning($errorMsg);
                continue;
            }

            $validation = $this->validator->validateSkill($skillInfo['path']);

            if (!$validation['valid']) {
                $errorMsg = "Skill '{$skillName}' failed validation: ".implode(', ', $validation['errors']);
                $errors[] = $errorMsg;
                $io->warning($errorMsg);
                continue;
            }

            // Build target path safely and validate it stays within output directory.
            $canonicalOutput = PathSecurity::canonicalizePath($outputPath);
            if ('' === $canonicalOutput) {
                $errorMsg = "Output path is invalid: {$outputPath}";
                $errors[] = $errorMsg;
                $io->error($errorMsg);
                continue;
            }

            // Resolve target path within output directory.
            $targetPath = PathSecurity::resolvePathWithinBase($sanitizedSkillName, $canonicalOutput);
            if (null === $targetPath) {
                $errorMsg = "Target path escapes output directory: {$sanitizedSkillName}";
                $errors[] = $errorMsg;
                $io->error($errorMsg);
                continue;
            }

            // Remove existing skill if present.
            if ($filesystem->exists($targetPath)) {
                $this->removeDirectory($targetPath, $filesystem);
            }

            // Copy skill directory.
            if ($this->copyDirectory($skillInfo['path'], $targetPath)) {
                ++$installed;
                $io->writeln("  ✓ Installed: {$skillName} (from {$skillInfo['source_type']})");
            } else {
                $errorMsg = "Failed to copy skill '{$skillName}'";
                $errors[] = $errorMsg;
                $io->error($errorMsg);
            }
        }

        $success = empty($errors) || $installed > 0;

        return [
            'success' => $success,
            'installed' => $installed,
            'errors' => $errors,
        ];
    }

    /**
     * Copy a directory recursively.
     *
     * @param string $source
     *                            Source directory path
     * @param string $destination
     *                            Destination directory path
     *
     * @return bool
     *              True on success
     */
    private function copyDirectory(string $source, string $destination): bool
    {
        // Validate source directory.
        if (!PathSecurity::isValidDirectory($source, false)) {
            return false;
        }

        // Canonicalize paths.
        $canonicalSource = PathSecurity::canonicalizePath($source);
        $canonicalDestination = PathSecurity::canonicalizePath($destination);

        if ('' === $canonicalSource) {
            return false;
        }

        // Create destination if it doesn't exist using Symfony Filesystem.
        $filesystem = new Filesystem();
        if ('' === $canonicalDestination) {
            try {
                $filesystem->mkdir($destination, 0755);
            } catch (IOExceptionInterface $e) {
                return false;
            }
            $canonicalDestination = PathSecurity::canonicalizePath($destination);
            if ('' === $canonicalDestination) {
                return false;
            }
        }

        // Use RecursiveDirectoryIterator without following symlinks (default behavior)
        // Note: FOLLOW_SYMLINKS flag is NOT set to prevent symlink traversal attacks.
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $canonicalSource,
                \RecursiveDirectoryIterator::SKIP_DOTS
            ),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        // Track source base length for relative path calculation.
        $sourceBaseLength = strlen($canonicalSource) + 1;

        foreach ($iterator as $item) {
            $itemPath = $item->getPathname();

            // Skip symlinks entirely for security (don't copy symlinks)
            if (PathSecurity::isSymlink($itemPath)) {
                continue;
            }

            // Validate item path stays within source directory.
            if (!PathSecurity::isPathWithinBase($itemPath, $canonicalSource)) {
                // Skip items outside source directory.
                continue;
            }

            // Canonicalize item path for validation.
            $canonicalItemPath = PathSecurity::canonicalizePath($itemPath);
            if ('' === $canonicalItemPath) {
                // Skip if path doesn't exist.
                continue;
            }

            // Double-check canonicalized path stays within source (prevent symlink escapes)
            if (!PathSecurity::isPathWithinBase($canonicalItemPath, $canonicalSource)) {
                // Skip items outside source directory.
                continue;
            }

            // Calculate relative path from original path (not canonicalized)
            $relativePath = substr($itemPath, $sourceBaseLength);

            // Sanitize each component of the relative path.
            $pathParts = explode(DIRECTORY_SEPARATOR, $relativePath);
            $sanitizedParts = [];
            foreach ($pathParts as $part) {
                $sanitized = PathSecurity::sanitizePathComponent($part);
                if ('' === $sanitized) {
                    // Invalid path component.
                    return false;
                }
                $sanitizedParts[] = $sanitized;
            }

            $safeRelativePath = implode(DIRECTORY_SEPARATOR, $sanitizedParts);
            $targetPath = PathSecurity::joinPaths($canonicalDestination, $safeRelativePath);

            // Validate target path stays within destination.
            $canonicalTarget = PathSecurity::canonicalizePath($targetPath);
            if ('' !== $canonicalTarget && !PathSecurity::isPathWithinBase($canonicalTarget, $canonicalDestination)) {
                // Path escapes destination.
                return false;
            }

            if ($item->isDir()) {
                if (!$filesystem->exists($targetPath)) {
                    try {
                        $filesystem->mkdir($targetPath, 0755);
                    } catch (IOExceptionInterface $e) {
                        return false;
                    }
                }
            } else {
                // Use Symfony Filesystem::copy() for safe file copying.
                try {
                    $filesystem->copy($itemPath, $targetPath, true);
                } catch (IOExceptionInterface $e) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Remove a directory recursively.
     *
     * @param string          $directory
     *                                    Directory path to remove
     * @param Filesystem|null $filesystem
     *                                    Optional Filesystem instance (for reuse)
     *
     * @return bool
     *              True on success
     */
    private function removeDirectory(string $directory, ?Filesystem $filesystem = null): bool
    {
        // Validate directory exists and is valid.
        if (!PathSecurity::isValidDirectory($directory, false)) {
            // Already removed or doesn't exist.
            return true;
        }

        // Canonicalize path.
        $canonicalDirectory = PathSecurity::canonicalizePath($directory);
        if ('' === $canonicalDirectory) {
            // Doesn't exist.
            return true;
        }

        // Ensure we're only removing directories within output path.
        $outputPath = SkillsConfig::getOutputSkillsPath($this->projectRoot);
        $canonicalOutput = PathSecurity::canonicalizePath($outputPath);
        if ('' !== $canonicalOutput && !PathSecurity::isPathWithinBase($canonicalDirectory, $canonicalOutput)) {
            // Cannot remove directory outside output path.
            return false;
        }

        // Use Symfony Filesystem::remove() for safe recursive removal
        // But first validate all paths stay within directory.
        $filesystem = $filesystem ?? new Filesystem();

        // Don't follow symlinks when removing directories.
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $canonicalDirectory,
                \RecursiveDirectoryIterator::SKIP_DOTS
            ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $itemPath = $item->getPathname();
            $canonicalItemPath = PathSecurity::canonicalizePath($itemPath);

            // Ensure item is still within directory being removed.
            if ('' !== $canonicalItemPath && !PathSecurity::isPathWithinBase($canonicalItemPath, $canonicalDirectory)) {
                // Skip items outside directory.
                continue;
            }

            // Skip symlinks for security.
            if (PathSecurity::isSymlink($itemPath)) {
                continue;
            }
        }

        // Use Symfony Filesystem::remove() for safe removal.
        try {
            $filesystem->remove($canonicalDirectory);

            return true;
        } catch (IOExceptionInterface $e) {
            return false;
        }
    }

    /**
     * Filter skills based on exclude configuration.
     *
     * @param array  $skills
     *                           Array of skills to filter
     * @param string $sourceName
     *                           Name of the source (package name, module name, git URL, etc.).
     * @param string $sourceType
     *                           Type of source ('composer', 'module', 'git', 'local')
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
            // Generate all possible identifier formats.
            $identifiers = [$sourceName.':'.$skillName];

            // Check exclude list - check all identifier formats.
            $isExcluded = false;
            foreach ($identifiers as $identifier) {
                if (in_array($identifier, $excludeSkills)) {
                    $isExcluded = true;
                    break;
                }
            }
            if ($isExcluded) {
                continue;
            }

            $filtered[$skillName] = $skillInfo;
        }

        return $filtered;
    }
}
