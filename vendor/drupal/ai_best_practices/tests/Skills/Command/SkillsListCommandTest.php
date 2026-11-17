<?php

declare(strict_types=1);

namespace Drupal\AiBestPractices\Tests\Skills\Command;

use Drupal\AiBestPractices\Skills\Command\SkillsListCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests for SkillsListCommand.
 */
class SkillsListCommandTest extends TestCase
{
    private string $tempDir;
    private string $originalCwd;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/abp-list-test-'.uniqid();
        mkdir($this->tempDir.'/skills', 0755, true);
        file_put_contents($this->tempDir.'/composer.json', "{}\n");
        file_put_contents($this->tempDir.'/ai_best_practices.yaml', <<<'YAML'
skills:
  enabled: true
  discover_composer: false
  discover_webroot: false
YAML);

        // One skill whose description is a plain multi-line scalar (valid YAML,
        // the drupal-canvas/skills style), one whose frontmatter is not valid YAML.
        mkdir($this->tempDir.'/skills/multi-line-skill', 0755, true);
        file_put_contents($this->tempDir.'/skills/multi-line-skill/SKILL.md', <<<'YAML'
---
name: multi-line-skill
description:
  Use when the description is written
  across several indented lines.
---
# Multi-line skill
YAML);

        mkdir($this->tempDir.'/skills/broken-skill', 0755, true);
        file_put_contents($this->tempDir.'/skills/broken-skill/SKILL.md', <<<'YAML'
---
name: broken-skill
description: Use when: an unquoted colon breaks the YAML
---
# Broken skill
YAML);

        $cwd = getcwd();
        $this->originalCwd = false === $cwd ? $this->tempDir : $cwd;
        chdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        chdir($this->originalCwd);
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    /**
     * The table lists every discovered skill and never dies on a bad description.
     */
    public function testTableListsValidAndInvalidSkills(): void
    {
        $tester = new CommandTester(new SkillsListCommand());
        $exitCode = $tester->execute([]);
        $display = $tester->getDisplay();

        $this->assertSame(0, $exitCode, $display);
        $this->assertStringContainsString('multi-line-skill', $display);
        $this->assertStringContainsString('Use when the description is written across', $display);
        $this->assertStringContainsString('broken-skill', $display);
        $this->assertStringContainsString('(no description)', $display);
        $this->assertStringContainsString('Found 2 skill(s), 1 valid', $display);
    }

    /**
     * XML output includes only valid skills, with the parsed description.
     */
    public function testXmlOutputIncludesOnlyValidSkills(): void
    {
        $tester = new CommandTester(new SkillsListCommand());
        $exitCode = $tester->execute(['--format' => 'xml']);
        $display = $tester->getDisplay();

        $this->assertSame(0, $exitCode, $display);
        $this->assertStringContainsString('<name>multi-line-skill</name>', $display);
        $this->assertStringContainsString(
            '<description>Use when the description is written across several indented lines.</description>',
            $display
        );
        $this->assertStringNotContainsString('broken-skill', $display);
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
