<?php

declare(strict_types=1);

namespace Drupal\AiBestPractices\Tests\Skills;

use Drupal\AiBestPractices\Skills\WebRootResolver;
use PHPUnit\Framework\TestCase;

/**
 * Tests for WebRootResolver.
 */
class WebRootResolverTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/abp-webroot-'.uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    public function testResolveFromDrupalScaffold(): void
    {
        mkdir($this->tempDir.'/web', 0755, true);
        file_put_contents($this->tempDir.'/composer.json', json_encode([
            'extra' => [
                'drupal-scaffold' => [
                    'locations' => [
                        'web-root' => 'web/',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $this->assertSame('web/', WebRootResolver::resolve($this->tempDir));
    }

    public function testResolveFromConfigOverride(): void
    {
        mkdir($this->tempDir.'/html', 0755, true);
        file_put_contents($this->tempDir.'/ai_best_practices.yaml', <<<'YAML'
skills:
  web_root: html/
YAML);

        $this->assertSame('html/', WebRootResolver::resolve($this->tempDir));
    }

    public function testResolveFromInstallerPaths(): void
    {
        mkdir($this->tempDir.'/html', 0755, true);
        file_put_contents($this->tempDir.'/composer.json', json_encode([
            'extra' => [
                'installer-paths' => [
                    'html/core' => ['type:drupal-core'],
                    'html/modules/contrib/{$name}' => ['type:drupal-module'],
                    'html/themes/contrib/{$name}' => ['type:drupal-theme'],
                    'html/profiles/contrib/{$name}' => ['type:drupal-profile'],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $this->assertSame('html/', WebRootResolver::resolve($this->tempDir));
    }

    public function testResolveFilesystemFallback(): void
    {
        mkdir($this->tempDir.'/web', 0755, true);

        $this->assertSame('web/', WebRootResolver::resolve($this->tempDir));
    }

    public function testResolveReturnsNullWhenUnknown(): void
    {
        $this->assertNull(WebRootResolver::resolve($this->tempDir));
    }

    public function testScaffoldWebRootFallsThroughWhenDirectoryMissing(): void
    {
        mkdir($this->tempDir.'/web', 0755, true);
        file_put_contents($this->tempDir.'/composer.json', json_encode([
            'extra' => [
                'drupal-scaffold' => [
                    'locations' => [
                        'web-root' => 'html/',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $this->assertSame('web/', WebRootResolver::resolve($this->tempDir));
    }

    public function testInstallerPathsIgnoresDrushPaths(): void
    {
        file_put_contents($this->tempDir.'/composer.json', json_encode([
            'extra' => [
                'installer-paths' => [
                    'drush/Commands/contrib/{$name}' => ['type:drupal-drush'],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $this->assertNull(WebRootResolver::resolve($this->tempDir));
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($directory);
    }
}
