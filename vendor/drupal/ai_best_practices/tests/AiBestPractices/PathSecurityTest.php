<?php

namespace Drupal\AiBestPractices\Tests\AiBestPractices;

use Drupal\AiBestPractices\PathSecurity;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PathSecurity utility class.
 */
class PathSecurityTest extends TestCase
{
    private string $tempDir;
    private string $baseDir;
    private string $subDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/abp-path-test-'.uniqid();
        $this->baseDir = $this->tempDir.'/base';
        $this->subDir = $this->baseDir.'/sub';
        mkdir($this->subDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    /**
     * Test canonicalizePath with existing path.
     */
    public function testCanonicalizePathExisting(): void
    {
        $canonical = PathSecurity::canonicalizePath($this->baseDir);
        $this->assertNotEmpty($canonical);
        $this->assertStringStartsWith('/', $canonical);
    }

    /**
     * Test canonicalizePath with non-existing path.
     */
    public function testCanonicalizePathNonExisting(): void
    {
        $canonical = PathSecurity::canonicalizePath($this->tempDir.'/nonexistent');
        $this->assertEquals('', $canonical);
    }

    /**
     * Test isPathWithinBase with valid path.
     */
    public function testIsPathWithinBaseValid(): void
    {
        $this->assertTrue(PathSecurity::isPathWithinBase($this->subDir, $this->baseDir));
    }

    /**
     * Test isPathWithinBase with path outside base.
     */
    public function testIsPathWithinBaseInvalid(): void
    {
        $outsidePath = $this->tempDir.'/outside';
        mkdir($outsidePath, 0755, true);
        $this->assertFalse(PathSecurity::isPathWithinBase($outsidePath, $this->baseDir));
    }

    /**
     * Test isPathWithinBase with non-existing paths.
     */
    public function testIsPathWithinBaseNonExisting(): void
    {
        $this->assertFalse(PathSecurity::isPathWithinBase(
            $this->tempDir.'/nonexistent',
            $this->baseDir
        ));
        $this->assertFalse(PathSecurity::isPathWithinBase(
            $this->subDir,
            $this->tempDir.'/nonexistent'
        ));
    }

    /**
     * Test sanitizePathComponent removes path traversal.
     */
    public function testSanitizePathComponentRemovesTraversal(): void
    {
        $this->assertEquals('', PathSecurity::sanitizePathComponent('../'));
        $this->assertEquals('', PathSecurity::sanitizePathComponent('..\\'));
        $this->assertEquals('', PathSecurity::sanitizePathComponent('./'));
        $this->assertEquals('', PathSecurity::sanitizePathComponent('.\\'));
    }

    /**
     * Test sanitizePathComponent removes directory separators.
     */
    public function testSanitizePathComponentRemovesSeparators(): void
    {
        $this->assertEquals('', PathSecurity::sanitizePathComponent('path/to/file'));
        $this->assertEquals('', PathSecurity::sanitizePathComponent('path\\to\\file'));
    }

    /**
     * Test sanitizePathComponent rejects null bytes.
     */
    public function testSanitizePathComponentRemovesNullBytes(): void
    {
        // Null bytes should cause complete rejection for security
        $this->assertEquals('', PathSecurity::sanitizePathComponent("test\0file"));
    }

    /**
     * Test sanitizePathComponent with valid component.
     */
    public function testSanitizePathComponentValid(): void
    {
        $this->assertEquals('valid-name', PathSecurity::sanitizePathComponent('valid-name'));
        $this->assertEquals('test123', PathSecurity::sanitizePathComponent('test123'));
    }

    /**
     * Test sanitizePathComponent rejects dangerous patterns.
     */
    public function testSanitizePathComponentRejectsDangerous(): void
    {
        $this->assertEquals('', PathSecurity::sanitizePathComponent('.'));
        $this->assertEquals('', PathSecurity::sanitizePathComponent('..'));
        $this->assertEquals('', PathSecurity::sanitizePathComponent(' . '));
    }

    /**
     * Test joinPaths combines paths correctly.
     */
    public function testJoinPaths(): void
    {
        $result = PathSecurity::joinPaths('base', 'sub', 'file');
        $this->assertStringContainsString('base', $result);
        $this->assertStringContainsString('sub', $result);
        $this->assertStringContainsString('file', $result);
    }

    /**
     * Test joinPaths handles empty parts.
     */
    public function testJoinPathsEmptyParts(): void
    {
        $result = PathSecurity::joinPaths('base', '', 'file');
        $this->assertStringContainsString('base', $result);
        $this->assertStringContainsString('file', $result);
    }

    /**
     * Test joinPaths with single part.
     */
    public function testJoinPathsSinglePart(): void
    {
        $result = PathSecurity::joinPaths('base');
        $this->assertEquals('base', $result);
    }

    /**
     * Test isValidPathComponent detects path traversal.
     */
    public function testIsValidPathComponentDetectsTraversal(): void
    {
        $this->assertFalse(PathSecurity::isValidPathComponent('../'));
        $this->assertFalse(PathSecurity::isValidPathComponent('..\\'));
        $this->assertFalse(PathSecurity::isValidPathComponent('./'));
    }

    /**
     * Test isValidPathComponent detects null bytes.
     */
    public function testIsValidPathComponentDetectsNullBytes(): void
    {
        $this->assertFalse(PathSecurity::isValidPathComponent("test\0file"));
    }

    /**
     * Test isValidPathComponent with valid component.
     */
    public function testIsValidPathComponentValid(): void
    {
        $this->assertTrue(PathSecurity::isValidPathComponent('valid-name'));
        $this->assertTrue(PathSecurity::isValidPathComponent('test123'));
    }

    /**
     * Test resolvePathWithinBase with valid relative path.
     */
    public function testResolvePathWithinBaseValid(): void
    {
        $result = PathSecurity::resolvePathWithinBase('sub', $this->baseDir);
        $this->assertNotNull($result);
        $this->assertTrue(PathSecurity::isPathWithinBase($result, $this->baseDir));
    }

    /**
     * Test resolvePathWithinBase blocks path traversal.
     */
    public function testResolvePathWithinBaseBlocksTraversal(): void
    {
        $result = PathSecurity::resolvePathWithinBase('../outside', $this->baseDir);
        $this->assertNull($result);
    }

    /**
     * Test resolvePathWithinBase sanitizes components.
     */
    public function testResolvePathWithinBaseSanitizes(): void
    {
        // Path traversal sequences are rejected entirely for security
        // "sub/../other" contains "../" which should be rejected
        $result = PathSecurity::resolvePathWithinBase('sub/../other', $this->baseDir);
        $this->assertNull($result, 'Path traversal sequences should be rejected');

        // Valid path should work
        $otherDir = $this->baseDir.'/other';
        mkdir($otherDir, 0755, true);
        $result = PathSecurity::resolvePathWithinBase('other', $this->baseDir);
        $this->assertNotNull($result);
        $this->assertTrue(PathSecurity::isPathWithinBase($result, $this->baseDir));
    }

    /**
     * Test isSymlink detects symlinks.
     */
    public function testIsSymlink(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Symlink tests may not work on Windows');
        }

        $linkPath = $this->baseDir.'/link';
        symlink($this->subDir, $linkPath);

        $this->assertTrue(PathSecurity::isSymlink($linkPath));
        $this->assertFalse(PathSecurity::isSymlink($this->subDir));
    }

    /**
     * Test isValidDirectory validates correctly.
     */
    public function testIsValidDirectory(): void
    {
        $this->assertTrue(PathSecurity::isValidDirectory($this->baseDir));
        $this->assertFalse(PathSecurity::isValidDirectory($this->tempDir.'/nonexistent'));

        // Create a file, not a directory
        $filePath = $this->baseDir.'/file.txt';
        file_put_contents($filePath, 'test');
        $this->assertFalse(PathSecurity::isValidDirectory($filePath));
    }

    /**
     * Test isValidDirectory rejects symlinks when not allowed.
     */
    public function testIsValidDirectoryRejectsSymlinks(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Symlink tests may not work on Windows');
        }

        $linkPath = $this->baseDir.'/link';
        symlink($this->subDir, $linkPath);

        $this->assertFalse(PathSecurity::isValidDirectory($linkPath, false));
        $this->assertTrue(PathSecurity::isValidDirectory($linkPath, true));
    }

    /**
     * Test path traversal attack prevention.
     */
    public function testPathTraversalPrevention(): void
    {
        $malicious = [
            '../',
            '..\\',
            '../../etc/passwd',
            '..\\..\\windows\\system32',
            'sub/../../etc',
            'sub\\..\\..\\etc',
        ];

        foreach ($malicious as $path) {
            $sanitized = PathSecurity::sanitizePathComponent($path);
            $this->assertEquals('', $sanitized, "Failed to sanitize: {$path}");
        }
    }

    /**
     * Test boundary validation prevents escapes.
     */
    public function testBoundaryValidation(): void
    {
        $outsidePath = $this->tempDir.'/outside';
        mkdir($outsidePath, 0755, true);

        // Try to resolve a path that would escape
        $result = PathSecurity::resolvePathWithinBase('../../outside', $this->baseDir);
        $this->assertNull($result, 'Should reject path that escapes base directory');

        // Valid path should work
        $result = PathSecurity::resolvePathWithinBase('sub', $this->baseDir);
        $this->assertNotNull($result);
        $this->assertTrue(PathSecurity::isPathWithinBase($result, $this->baseDir));
    }

    public function testIsRelativePathUnderPrefix(): void
    {
        $this->assertTrue(PathSecurity::isRelativePathUnderPrefix('web', 'web/'));
        $this->assertTrue(PathSecurity::isRelativePathUnderPrefix('web/modules/custom/foo', 'web/'));
        $this->assertTrue(PathSecurity::isRelativePathUnderPrefix('vendor/drupal/core', 'vendor/'));
        $this->assertFalse(PathSecurity::isRelativePathUnderPrefix('webroot/modules', 'web/'));
        $this->assertFalse(PathSecurity::isRelativePathUnderPrefix('html/modules', 'web/'));
        $this->assertFalse(PathSecurity::isRelativePathUnderPrefix('web/modules', ''));
    }

    /**
     * Remove directory recursively.
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir.'/'.$file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
