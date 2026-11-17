<?php

namespace Drupal\AiBestPractices\Tests\Skills;

use Drupal\AiBestPractices\Skills\SkillsScanner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Tests for SkillsScanner.
 */
class SkillsScannerTest extends TestCase
{
    private string $tempDir;
    private string $configFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/abp-scanner-test-'.uniqid();
        mkdir($this->tempDir, 0755, true);

        // Create config file
        $this->configFile = $this->tempDir.'/ai_best_practices.yaml';
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    /**
     * Test scanning finds project-level skills.
     */
    public function testScanProjectSkills(): void
    {
        // Create config with skills enabled
        $config = <<<'YAML'
skills:
  enabled: true
  discover_composer: false
  discover_webroot: false
YAML;
        file_put_contents($this->configFile, $config);

        // Create project-level skill
        mkdir($this->tempDir.'/skills', 0755, true);
        $skillPath = $this->tempDir.'/skills/test-skill';
        mkdir($skillPath, 0755, true);

        $skillMd = <<<'YAML'
---
name: test-skill
description: A test skill
---
YAML;
        file_put_contents($skillPath.'/SKILL.md', $skillMd);

        $scanner = new SkillsScanner($this->tempDir);
        $io = new SymfonyStyle(new ArrayInput([]), new NullOutput());
        $skills = $scanner->scanAllSkills($io);

        $this->assertArrayHasKey('test-skill', $skills);
        $this->assertEquals('project', $skills['test-skill']['source_type']);
    }

    /**
     * Test scanning respects excluded directories.
     */
    public function testScanRespectsExclusions(): void
    {
        $config = <<<'YAML'
skills:
  enabled: true
  discover_composer: true
  discover_webroot: true
  excluded_directories:
    - excluded
YAML;
        file_put_contents($this->configFile, $config);

        // Create skill in excluded directory
        mkdir($this->tempDir.'/excluded', 0755, true);
        mkdir($this->tempDir.'/excluded/skills', 0755, true);
        $skillPath = $this->tempDir.'/excluded/skills/test-skill';
        mkdir($skillPath, 0755, true);

        $skillMd = <<<'YAML'
---
name: test-skill
description: A test skill
---
YAML;
        file_put_contents($skillPath.'/SKILL.md', $skillMd);

        $scanner = new SkillsScanner($this->tempDir);
        $io = new SymfonyStyle(new ArrayInput([]), new NullOutput());
        $skills = $scanner->scanAllSkills($io);

        // Should not find skill in excluded directory
        $this->assertArrayNotHasKey('test-skill', $skills);
    }

    public function testScanWebRootThemeSkills(): void
    {
        $this->writeScannerConfig(discoverComposer: false, discoverWebroot: true);
        $this->ensureWebRoot();
        $this->createWebRootSkill(
            'web/themes/custom/mytheme/.agents/skills/theme-skill',
            'theme-skill'
        );

        $skills = $this->scanSkills();

        $this->assertArrayHasKey('theme-skill', $skills);
        $this->assertSame('webroot', $skills['theme-skill']['source_type']);
        $this->assertSame('mytheme', $skills['theme-skill']['source_name']);
    }

    public function testScanWebRootProfileSkills(): void
    {
        $this->writeScannerConfig(discoverComposer: false, discoverWebroot: true);
        $this->ensureWebRoot();
        $this->createWebRootSkill(
            'web/profiles/custom/myprofile/skills/profile-skill',
            'profile-skill'
        );

        $skills = $this->scanSkills();

        $this->assertArrayHasKey('profile-skill', $skills);
        $this->assertSame('webroot', $skills['profile-skill']['source_type']);
        $this->assertSame('myprofile', $skills['profile-skill']['source_name']);
    }

    public function testScanWebRootModuleSkills(): void
    {
        $this->writeScannerConfig(discoverComposer: false, discoverWebroot: true);
        $this->ensureWebRoot();
        $this->createWebRootSkill(
            'web/modules/custom/mymodule/.agents/skills/module-skill',
            'module-skill'
        );

        $skills = $this->scanSkills();

        $this->assertArrayHasKey('module-skill', $skills);
        $this->assertSame('webroot', $skills['module-skill']['source_type']);
        $this->assertSame('mymodule', $skills['module-skill']['source_name']);
    }

    public function testScanWebRootRespectsConfig(): void
    {
        $this->writeScannerConfig(discoverComposer: false, discoverWebroot: false);
        $this->ensureWebRoot();
        $this->createWebRootSkill(
            'web/themes/custom/mytheme/.agents/skills/theme-skill',
            'theme-skill'
        );

        $skills = $this->scanSkills();

        $this->assertArrayNotHasKey('theme-skill', $skills);
    }

    public function testDiscoverModulesAlias(): void
    {
        file_put_contents($this->configFile, <<<'YAML'
skills:
  enabled: true
  discover_composer: false
  discover_modules: true
YAML);
        $this->ensureWebRoot();
        $this->createWebRootSkill(
            'web/themes/custom/mytheme/.agents/skills/theme-skill',
            'theme-skill'
        );

        $skills = $this->scanSkills();

        $this->assertArrayHasKey('theme-skill', $skills);
        $this->assertSame('webroot', $skills['theme-skill']['source_type']);
    }

    public function testDiscoverModulesFalseDisablesWebroot(): void
    {
        file_put_contents($this->configFile, <<<'YAML'
skills:
  enabled: true
  discover_composer: false
  discover_modules: false
YAML);
        $this->ensureWebRoot();
        $this->createWebRootSkill(
            'web/themes/custom/mytheme/.agents/skills/theme-skill',
            'theme-skill'
        );

        $skills = $this->scanSkills();

        $this->assertArrayNotHasKey('theme-skill', $skills);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function scanSkills(): array
    {
        $scanner = new SkillsScanner($this->tempDir);
        $io = new SymfonyStyle(new ArrayInput([]), new NullOutput());

        return $scanner->scanAllSkills($io);
    }

    private function writeScannerConfig(bool $discoverComposer, bool $discoverWebroot): void
    {
        $composer = $discoverComposer ? 'true' : 'false';
        $webroot = $discoverWebroot ? 'true' : 'false';
        file_put_contents($this->configFile, <<<YAML
skills:
  enabled: true
  discover_composer: {$composer}
  discover_webroot: {$webroot}
YAML);
    }

    private function ensureWebRoot(): void
    {
        mkdir($this->tempDir.'/web', 0755, true);
    }

    private function createWebRootSkill(string $relativeSkillPath, string $skillName): void
    {
        $skillPath = $this->tempDir.'/'.$relativeSkillPath;
        mkdir($skillPath, 0755, true);
        file_put_contents($skillPath.'/SKILL.md', <<<YAML
---
name: {$skillName}
description: A test skill
---
YAML);
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
