<?php

declare(strict_types=1);

namespace Drupal\AiBestPractices;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

/**
 * Path security utility class for safe path operations.
 *
 * Provides methods to prevent directory traversal, symlink attacks,
 * and other path-related security vulnerabilities.
 *
 * Uses Symfony Filesystem component for path manipulation while adding
 * security validation layers.
 */
class PathSecurity
{
    /**
     * Canonicalize a path by resolving symlinks and normalizing it.
     *
     * Uses Symfony Path::canonicalize() for normalization, then realpath()
     * to resolve symlinks if the path exists.
     *
     * @param string $path
     *                     The path to canonicalize
     *
     * @return string
     *                The canonicalized path, or empty string if path doesn't exist
     */
    public static function canonicalizePath(string $path): string
    {
        // First normalize with Symfony Path (handles ".." segments safely)
        $normalized = Path::canonicalize($path);

        // Then resolve symlinks if path exists.
        $canonical = realpath($normalized);

        // realpath() returns false if path doesn't exist.
        if (false === $canonical) {
            return '';
        }

        return $canonical;
    }

    /**
     * Check if a path is within a base directory.
     *
     * Uses Symfony Path::isBasePath() for validation, with additional
     * symlink resolution for security.
     *
     * @param string $path
     *                         The path to check
     * @param string $basePath
     *                         The base directory path
     *
     * @return bool
     *              True if path is within basePath, false otherwise
     */
    public static function isPathWithinBase(string $path, string $basePath): bool
    {
        // Canonicalize both paths to resolve symlinks.
        $canonicalPath = self::canonicalizePath($path);
        $canonicalBase = self::canonicalizePath($basePath);

        // If either path doesn't exist, use Symfony Path::isBasePath() on normalized paths.
        if ('' === $canonicalPath || '' === $canonicalBase) {
            $normalizedPath = Path::canonicalize($path);
            $normalizedBase = Path::canonicalize($basePath);

            return Path::isBasePath($normalizedBase, $normalizedPath);
        }

        // Use Symfony Path::isBasePath() with canonicalized paths.
        return Path::isBasePath($canonicalBase, $canonicalPath);
    }

    /**
     * Check whether a relative path from a base is under a given relative prefix.
     *
     * @param string $relativePath
     *                               Path relative to project root (forward slashes)
     * @param string $relativePrefix
     *                               Prefix relative to project root (e.g. "web/" or "vendor/")
     *
     * @return bool
     *              True when relativePath equals the prefix or is nested under it
     */
    public static function isRelativePathUnderPrefix(string $relativePath, string $relativePrefix): bool
    {
        $normalizedPrefix = rtrim(str_replace('\\', '/', $relativePrefix), '/');
        if ('' === $normalizedPrefix) {
            return false;
        }

        $normalizedRelative = str_replace('\\', '/', $relativePath);
        $normalizedRelative = trim($normalizedRelative, '/');

        return $normalizedRelative === $normalizedPrefix
            || str_starts_with($normalizedRelative, $normalizedPrefix.'/');
    }

    /**
     * Sanitize a path component (directory or file name).
     *
     * Removes path traversal sequences and other dangerous characters.
     * This is a security layer on top of Symfony's path utilities.
     *
     * @param string $component
     *                          The path component to sanitize
     *
     * @return string
     *                The sanitized component, or empty string if invalid
     */
    public static function sanitizePathComponent(string $component): string
    {
        // Check for null bytes - reject entirely if found.
        if (false !== strpos($component, "\0")) {
            return '';
        }

        // Check for path traversal sequences - reject entirely if found.
        if (false !== strpos($component, '../')
              || false !== strpos($component, '..\\')
              || false !== strpos($component, './')
              || false !== strpos($component, '.\\')) {
            return '';
        }

        // Check for directory separators - reject entirely if found.
        if (false !== strpos($component, DIRECTORY_SEPARATOR)
              || false !== strpos($component, '/')
              || false !== strpos($component, '\\')) {
            return '';
        }

        // Remove leading/trailing dots and spaces.
        $component = trim($component, '. ');

        // Reject empty components or components that are just dots.
        if ('' === $component || '.' === $component || '..' === $component) {
            return '';
        }

        return $component;
    }

    /**
     * Join path components safely.
     *
     * Uses Symfony Path::join() for safe path joining.
     *
     * @param string ...$parts
     *                         Path components to join.
     *
     * @return string
     *                The joined path
     */
    public static function joinPaths(string ...$parts): string
    {
        $parts = array_filter($parts, function ($part) {
            return '' !== $part;
        });

        if (empty($parts)) {
            return '';
        }

        return Path::join(...$parts);
    }

    /**
     * Validate that a path component is safe to use.
     *
     * Checks for path traversal sequences and other dangerous patterns.
     *
     * @param string $component
     *                          The path component to validate
     *
     * @return bool
     *              True if safe, false otherwise
     */
    public static function isValidPathComponent(string $component): bool
    {
        return '' !== self::sanitizePathComponent($component);
    }

    /**
     * Resolve a relative path against a base path and validate it stays within base.
     *
     * Uses Symfony Path utilities with additional security validation.
     *
     * @param string $relativePath
     *                             The relative path to resolve
     * @param string $basePath
     *                             The base directory
     *
     * @return string|null
     *                     The resolved absolute path if valid, null if path escapes base directory
     */
    public static function resolvePathWithinBase(string $relativePath, string $basePath): ?string
    {
        // Canonicalize base path first.
        $canonicalBase = self::canonicalizePath($basePath);
        if ('' === $canonicalBase) {
            // If base doesn't exist, use normalized path.
            $canonicalBase = Path::canonicalize($basePath);
        }

        // Handle empty relative path (just return base)
        $relativePath = trim($relativePath);
        if ('' === $relativePath || '.' === $relativePath) {
            return $canonicalBase;
        }

        // Sanitize relative path components.
        $parts = explode('/', str_replace('\\', '/', $relativePath));
        $sanitizedParts = [];

        foreach ($parts as $part) {
            if ('' === $part || '.' === $part) {
                // Skip empty parts and current directory.
                continue;
            }
            $sanitized = self::sanitizePathComponent($part);
            if ('' === $sanitized) {
                // Invalid component found.
                return null;
            }
            $sanitizedParts[] = $sanitized;
        }

        if (empty($sanitizedParts)) {
            return $canonicalBase;
        }

        // Build absolute path using Symfony Path::join()
        $resolvedPath = Path::join($canonicalBase, ...$sanitizedParts);

        // Canonicalize if path exists.
        $canonical = self::canonicalizePath($resolvedPath);

        if ('' !== $canonical) {
            // Path exists - validate it stays within base using Symfony Path::isBasePath()
            if (!Path::isBasePath($canonicalBase, $canonical)) {
                return null;
            }

            return $canonical;
        }

        // Path doesn't exist yet - validate by checking if resolved path is within base
        // Use Symfony Path::isBasePath() for validation.
        if (!Path::isBasePath($canonicalBase, $resolvedPath)) {
            return null;
        }

        // Validate each parent directory exists and is within base.
        $currentPath = $canonicalBase;
        foreach ($sanitizedParts as $part) {
            $currentPath = Path::join($currentPath, $part);
            $parentPath = Path::getDirectory($currentPath);
            $canonicalParent = self::canonicalizePath($parentPath);
            if ('' === $canonicalParent || !Path::isBasePath($canonicalBase, $canonicalParent)) {
                return null;
            }
        }

        // All components validated - return the resolved path.
        return $resolvedPath;
    }

    /**
     * Check if a path is a symlink.
     *
     * @param string $path
     *                     The path to check
     *
     * @return bool
     *              True if path is a symlink, false otherwise
     */
    public static function isSymlink(string $path): bool
    {
        $filesystem = new Filesystem();
        if (!$filesystem->exists($path)) {
            return false;
        }

        return is_link($path);
    }

    /**
     * Validate that a directory path is safe for recursive operations.
     *
     * Checks that the path exists, is a directory, and is not a symlink
     * (unless explicitly allowed).
     *
     * @param string $path
     *                              The directory path to validate
     * @param bool   $allowSymlinks
     *                              Whether to allow symlinks (default: false)
     *
     * @return bool
     *              True if safe, false otherwise
     */
    public static function isValidDirectory(string $path, bool $allowSymlinks = false): bool
    {
        $filesystem = new Filesystem();
        if (!$filesystem->exists($path)) {
            return false;
        }

        if (!is_dir($path)) {
            return false;
        }

        if (!$allowSymlinks && self::isSymlink($path)) {
            return false;
        }

        return true;
    }

    /**
     * Check if a file path is valid and safe.
     *
     * @param string $path
     *                              The file path to validate
     * @param bool   $allowSymlinks
     *                              Whether to allow symlinks (default: false)
     *
     * @return bool
     *              True if safe, false otherwise
     */
    public static function isValidFile(string $path, bool $allowSymlinks = false): bool
    {
        $filesystem = new Filesystem();
        if (!$filesystem->exists($path)) {
            return false;
        }

        if (!is_file($path)) {
            return false;
        }

        if (!$allowSymlinks && self::isSymlink($path)) {
            return false;
        }

        return true;
    }
}
