<?php

namespace Drupal\AiBestPractices\Tests\Skills;

use Drupal\AiBestPractices\Skills\SkillsAggregator;
use Drupal\AiBestPractices\Skills\SkillsConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Tests for SkillsAggregator.
 */
class SkillsAggregatorTest extends TestCase
{
    private string $tempDir;
    private string $configFile;
    private string $outputDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/abp-aggregator-test-'.uniqid();
        mkdir($this->tempDir, 0755, true);

        // Create config file
        $this->configFile = $this->tempDir.'/ai_best_practices.yaml';

        // Create output directory (matches default from ai_best_practices.yaml merge).
        $this->outputDir = $this->tempDir.'/'.SkillsConfig::OUTPUT_SKILLS_DIR;
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    /**
     * Test that manually added skills are preserved.
     */
    public function testManuallyAddedSkillsArePreserved(): void
    {
        // Create config with skills enabled
        $config = <<<'YAML'
skills:
  enabled: true
  discover_composer: false
  discover_modules: false
YAML;
        file_put_contents($this->configFile, $config);

        // Create a manually added skill in output directory
        mkdir($this->outputDir, 0755, true);
        $manualSkillPath = $this->outputDir.'/manual-skill';
        mkdir($manualSkillPath, 0755, true);

        $skillMd = <<<'YAML'
---
name: manual-skill
description: A manually added skill
---
YAML;
        file_put_contents($manualSkillPath.'/SKILL.md', $skillMd);
        file_put_contents($manualSkillPath.'/custom-file.txt', 'custom content');

        $aggregator = new SkillsAggregator($this->tempDir);
        $io = new SymfonyStyle(new ArrayInput([]), new NullOutput());
        $result = $aggregator->aggregate($io);

        // Should succeed with no skills installed (no sources)
        $this->assertTrue($result['success']);
        $this->assertEquals(0, $result['installed']);

        // Manual skill should still exist
        $this->assertDirectoryExists($manualSkillPath);
        $this->assertFileExists($manualSkillPath.'/SKILL.md');
        $this->assertFileExists($manualSkillPath.'/custom-file.txt');
    }

    /**
     * Test that skills from sources are installed/updated.
     */
    public function testSkillsFromSourcesAreInstalled(): void
    {
        // Create config with skills enabled
        $config = <<<'YAML'
skills:
  enabled: true
  discover_composer: false
  discover_modules: false
YAML;
        file_put_contents($this->configFile, $config);

        // Create a project-level skill (source)
        mkdir($this->tempDir.'/skills', 0755, true);
        $sourceSkillPath = $this->tempDir.'/skills/source-skill';
        mkdir($sourceSkillPath, 0755, true);

        $skillMd = <<<'YAML'
---
name: source-skill
description: A skill from source
---
YAML;
        file_put_contents($sourceSkillPath.'/SKILL.md', $skillMd);
        file_put_contents($sourceSkillPath.'/source-file.txt', 'source content');

        $aggregator = new SkillsAggregator($this->tempDir);
        $io = new SymfonyStyle(new ArrayInput([]), new NullOutput());
        $result = $aggregator->aggregate($io);

        // Should succeed and install the skill
        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['installed']);

        // Skill should be installed in output directory
        $installedSkillPath = $this->outputDir.'/source-skill';
        $this->assertDirectoryExists($installedSkillPath);
        $this->assertFileExists($installedSkillPath.'/SKILL.md');
        $this->assertFileExists($installedSkillPath.'/source-file.txt');
    }

    /**
     * Test that skills from removed packages are preserved.
     */
    public function testSkillsFromRemovedPackagesArePreserved(): void
    {
        // Create config with skills enabled
        $config = <<<'YAML'
skills:
  enabled: true
  discover_composer: false
  discover_modules: false
YAML;
        file_put_contents($this->configFile, $config);

        // Create output directory with a skill that was previously installed from a source
        // but the source no longer exists
        mkdir($this->outputDir, 0755, true);
        $removedSkillPath = $this->outputDir.'/removed-skill';
        mkdir($removedSkillPath, 0755, true);

        $skillMd = <<<'YAML'
---
name: removed-skill
description: A skill from a removed package
---
YAML;
        file_put_contents($removedSkillPath.'/SKILL.md', $skillMd);
        file_put_contents($removedSkillPath.'/old-file.txt', 'old content');

        $aggregator = new SkillsAggregator($this->tempDir);
        $io = new SymfonyStyle(new ArrayInput([]), new NullOutput());
        $result = $aggregator->aggregate($io);

        // Should succeed with no skills installed (no sources)
        $this->assertTrue($result['success']);
        $this->assertEquals(0, $result['installed']);

        // Skill from removed package should still exist (preserved as unmanaged)
        $this->assertDirectoryExists($removedSkillPath);
        $this->assertFileExists($removedSkillPath.'/SKILL.md');
        $this->assertFileExists($removedSkillPath.'/old-file.txt');
    }

    /**
     * Test conflict resolution - manual skill with same name as source gets overwritten.
     */
    public function testConflictResolutionManualSkillGetsOverwritten(): void
    {
        // Create config with skills enabled
        $config = <<<'YAML'
skills:
  enabled: true
  discover_composer: false
  discover_modules: false
YAML;
        file_put_contents($this->configFile, $config);

        // Create a manually added skill
        mkdir($this->outputDir, 0755, true);
        $manualSkillPath = $this->outputDir.'/conflict-skill';
        mkdir($manualSkillPath, 0755, true);

        $manualSkillMd = <<<'YAML'
---
name: conflict-skill
description: Manual version
---
YAML;
        file_put_contents($manualSkillPath.'/SKILL.md', $manualSkillMd);
        file_put_contents($manualSkillPath.'/manual-file.txt', 'manual content');

        // Create a project-level skill with the same name (source)
        mkdir($this->tempDir.'/skills', 0755, true);
        $sourceSkillPath = $this->tempDir.'/skills/conflict-skill';
        mkdir($sourceSkillPath, 0755, true);

        $sourceSkillMd = <<<'YAML'
---
name: conflict-skill
description: Source version
---
YAML;
        file_put_contents($sourceSkillPath.'/SKILL.md', $sourceSkillMd);
        file_put_contents($sourceSkillPath.'/source-file.txt', 'source content');

        $aggregator = new SkillsAggregator($this->tempDir);
        $io = new SymfonyStyle(new ArrayInput([]), new NullOutput());
        $result = $aggregator->aggregate($io);

        // Should succeed and install the skill from source
        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['installed']);

        // Manual skill should be overwritten by source version
        $installedSkillPath = $this->outputDir.'/conflict-skill';
        $this->assertDirectoryExists($installedSkillPath);
        $this->assertFileExists($installedSkillPath.'/SKILL.md');
        $this->assertFileExists($installedSkillPath.'/source-file.txt');

        // Manual file should be gone (overwritten)
        $this->assertFileDoesNotExist($installedSkillPath.'/manual-file.txt');

        // Content should be from source
        $this->assertStringContainsString('Source version', file_get_contents($installedSkillPath.'/SKILL.md'));
    }

    /**
     * Test that .claude/skills in vendor/modules are scanned as sources.
     */
    public function testClaudeSkillsInVendorAreScanned(): void
    {
        // Create config with composer skills enabled
        $config = <<<'YAML'
skills:
  enabled: true
  discover_composer: true
  discover_modules: false
YAML;
        file_put_contents($this->configFile, $config);

        // Create a vendor package with .claude/skills
        mkdir($this->tempDir.'/vendor', 0755, true);
        mkdir($this->tempDir.'/vendor/test-package', 0755, true);
        mkdir($this->tempDir.'/vendor/test-package/.claude', 0755, true);
        mkdir($this->tempDir.'/vendor/test-package/.claude/skills', 0755, true);
        $vendorSkillPath = $this->tempDir.'/vendor/test-package/.claude/skills/vendor-skill';
        mkdir($vendorSkillPath, 0755, true);

        $skillMd = <<<'YAML'
---
name: vendor-skill
description: A skill from vendor package
---
YAML;
        file_put_contents($vendorSkillPath.'/SKILL.md', $skillMd);

        $aggregator = new SkillsAggregator($this->tempDir);
        $io = new SymfonyStyle(new ArrayInput([]), new NullOutput());
        $result = $aggregator->aggregate($io);

        // Should succeed and install the skill from vendor
        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['installed']);

        // Skill should be installed in output directory
        $installedSkillPath = $this->outputDir.'/vendor-skill';
        $this->assertDirectoryExists($installedSkillPath);
        $this->assertFileExists($installedSkillPath.'/SKILL.md');
    }

    /**
     * Test that .agents/skills in vendor packages are scanned as sources.
     */
    public function testAgentsSkillsInVendorAreScanned(): void
    {
        $config = <<<'YAML'
skills:
  enabled: true
  discover_composer: true
  discover_modules: false
YAML;
        file_put_contents($this->configFile, $config);

        mkdir($this->tempDir.'/vendor', 0755, true);
        mkdir($this->tempDir.'/vendor/test-package', 0755, true);
        mkdir($this->tempDir.'/vendor/test-package/.agents', 0755, true);
        mkdir($this->tempDir.'/vendor/test-package/.agents/skills', 0755, true);
        $vendorSkillPath = $this->tempDir.'/vendor/test-package/.agents/skills/agents-vendor-skill';
        mkdir($vendorSkillPath, 0755, true);

        $skillMd = <<<'YAML'
---
name: agents-vendor-skill
description: A skill from vendor .agents/skills
---
YAML;
        file_put_contents($vendorSkillPath.'/SKILL.md', $skillMd);

        $aggregator = new SkillsAggregator($this->tempDir);
        $io = new SymfonyStyle(new ArrayInput([]), new NullOutput());
        $result = $aggregator->aggregate($io);

        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['installed']);

        $installedSkillPath = $this->outputDir.'/agents-vendor-skill';
        $this->assertDirectoryExists($installedSkillPath);
        $this->assertFileExists($installedSkillPath.'/SKILL.md');
    }

    /**
     * Remove directory recursively.
     */
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
