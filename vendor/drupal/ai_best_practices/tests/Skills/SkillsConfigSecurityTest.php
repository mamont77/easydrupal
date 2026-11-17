<?php

declare(strict_types=1);

namespace Drupal\AiBestPractices\Tests\Skills;

use Drupal\AiBestPractices\PathSecurity;
use Drupal\AiBestPractices\Skills\SkillsConfig;
use PHPUnit\Framework\TestCase;

/**
 * Security-related tests for SkillsConfig paths.
 */
class SkillsConfigSecurityTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/abp-skills-cfg-'.uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    public function testResolveValidatedSkillsOutputPathAcceptsNestedRelative(): void
    {
        $path = SkillsConfig::resolveValidatedSkillsOutputPath($this->tempDir, '.agents/skills');
        $base = PathSecurity::canonicalizePath($this->tempDir);
        $this->assertNotEmpty($base);
        $this->assertTrue(PathSecurity::isPathWithinBase($path, $base));
    }

    public function testResolveValidatedSkillsOutputPathRejectsParentTraversal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('skills.output_directory must resolve inside the project root');
        SkillsConfig::resolveValidatedSkillsOutputPath($this->tempDir, '../outside-skills');
    }

    public function testResolveValidatedSkillsOutputPathRejectsAbsolute(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('skills.output_directory must be a relative path');
        SkillsConfig::resolveValidatedSkillsOutputPath($this->tempDir, '/tmp/evil');
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
