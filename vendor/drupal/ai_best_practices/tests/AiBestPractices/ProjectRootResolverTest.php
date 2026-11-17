<?php

declare(strict_types=1);

namespace Drupal\AiBestPractices\Tests\AiBestPractices;

use Drupal\AiBestPractices\PathSecurity;
use Drupal\AiBestPractices\ProjectRootResolver;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ProjectRootResolver.
 */
class ProjectRootResolverTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/abp-projroot-test-'.uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    public function testResolveWalksUpToComposerJson(): void
    {
        $root = $this->tempDir.'/myproject';
        mkdir($root.'/docroot/sites/default', 0755, true);
        file_put_contents($root.'/composer.json', '{}');

        $resolved = ProjectRootResolver::resolve($root.'/docroot/sites/default');
        $expected = PathSecurity::canonicalizePath($root);
        $this->assertNotEmpty($expected);
        $this->assertSame($expected, $resolved);
    }

    public function testResolveThrowsWhenComposerJsonMissing(): void
    {
        $empty = $this->tempDir.'/nocomposer';
        mkdir($empty.'/a/b', 0755, true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not find composer.json');
        ProjectRootResolver::resolve($empty.'/a/b');
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir.'/'.$file;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
