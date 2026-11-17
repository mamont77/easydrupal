<?php

declare(strict_types=1);

namespace Drupal\AiBestPractices\Tests\AiBestPractices\Config;

use Drupal\AiBestPractices\Config\AiBestPracticesConfigLoader;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AiBestPracticesConfigLoader fail-closed behavior.
 */
class AiBestPracticesConfigLoaderTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/abp-config-test-'.uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Missing file — must return defaults (no throw)
    // -------------------------------------------------------------------------

    public function testMissingConfigReturnsDefaults(): void
    {
        $config = AiBestPracticesConfigLoader::load($this->tempDir);

        $this->assertIsArray($config);
        $this->assertTrue($config['skills']['enabled']);
        $this->assertSame('.agents/skills', $config['skills']['output_directory']);
        $this->assertTrue($config['agents']['enabled']);
    }

    // -------------------------------------------------------------------------
    // Valid config — must merge and return
    // -------------------------------------------------------------------------

    public function testValidConfigMergesOverDefaults(): void
    {
        file_put_contents(
            $this->tempDir.'/ai_best_practices.yaml',
            "skills:\n  enabled: false\n"
        );

        $config = AiBestPracticesConfigLoader::load($this->tempDir);

        $this->assertFalse($config['skills']['enabled']);
        // Unrelated defaults must still be present.
        $this->assertSame('.agents/skills', $config['skills']['output_directory']);
    }

    // -------------------------------------------------------------------------
    // Malformed YAML — must throw, not silently fall back
    // -------------------------------------------------------------------------

    public function testMalformedYamlThrowsInvalidArgumentException(): void
    {
        file_put_contents(
            $this->tempDir.'/ai_best_practices.yaml',
            "skills:\n  enabled: [\nbad yaml here\n"
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('malformed YAML');

        AiBestPracticesConfigLoader::load($this->tempDir);
    }

    public function testMalformedYamlExceptionContainsFilePath(): void
    {
        file_put_contents(
            $this->tempDir.'/ai_best_practices.yaml',
            "skills:\n  enabled: [\nbad yaml here\n"
        );

        try {
            AiBestPracticesConfigLoader::load($this->tempDir);
            $this->fail('Expected \InvalidArgumentException was not thrown.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('ai_best_practices.yaml', $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Empty mapping {} — must return defaults (not throw)
    // -------------------------------------------------------------------------

    public function testEmptyMappingYamlReturnsDefaults(): void
    {
        // {} is a valid YAML mapping with no keys; should merge to defaults cleanly.
        file_put_contents(
            $this->tempDir.'/ai_best_practices.yaml',
            "{}\n"
        );

        $config = AiBestPracticesConfigLoader::load($this->tempDir);

        $this->assertIsArray($config);
        $this->assertTrue($config['skills']['enabled']);
    }

    // -------------------------------------------------------------------------
    // Scalar / non-mapping YAML — must throw, not silently fall back
    // -------------------------------------------------------------------------

    public function testScalarYamlThrowsInvalidArgumentException(): void
    {
        // A YAML file that parses to a string, not an array.
        file_put_contents(
            $this->tempDir.'/ai_best_practices.yaml',
            "just a string\n"
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a YAML mapping');

        AiBestPracticesConfigLoader::load($this->tempDir);
    }

    // -------------------------------------------------------------------------
    // Empty / whitespace-only file — valid YAML that parses to null;
    // semantically absent, so must return defaults (same path as missing file).
    // -------------------------------------------------------------------------

    public function testEmptyFileReturnsDefaults(): void
    {
        // A zero-byte file produces null from Yaml::parse.
        file_put_contents(
            $this->tempDir.'/ai_best_practices.yaml',
            ''
        );

        $config = AiBestPracticesConfigLoader::load($this->tempDir);

        $this->assertIsArray($config);
        $this->assertTrue($config['skills']['enabled']);
        $this->assertSame('.agents/skills', $config['skills']['output_directory']);
    }

    public function testWhitespaceOnlyFileReturnsDefaults(): void
    {
        // A whitespace-only file (spaces and newlines; tabs are a YAML parse
        // error) is syntactically valid YAML and semantically absent; it must
        // follow the same path as a missing file (defaults), not throw.
        file_put_contents(
            $this->tempDir.'/ai_best_practices.yaml',
            "\n   \n  \n"
        );

        $config = AiBestPracticesConfigLoader::load($this->tempDir);

        $this->assertIsArray($config);
        $this->assertTrue($config['skills']['enabled']);
        $this->assertSame('.agents/skills', $config['skills']['output_directory']);
    }

    public function testListYamlThrowsInvalidArgumentException(): void
    {
        // A YAML list (sequence), not a mapping.
        file_put_contents(
            $this->tempDir.'/ai_best_practices.yaml',
            "- item1\n- item2\n"
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a YAML mapping');

        AiBestPracticesConfigLoader::load($this->tempDir);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff((array) scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir.'/'.$file;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
