<?php

namespace Drupal\AiBestPractices\Tests\Skills;

use Drupal\AiBestPractices\Skills\SkillValidator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SkillValidator.
 */
class SkillValidatorTest extends TestCase
{
    private SkillValidator $validator;
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new SkillValidator();
        $this->tempDir = sys_get_temp_dir().'/abp-skill-test-'.uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    /**
     * Test validation of a valid skill.
     */
    public function testValidSkill(): void
    {
        $skillPath = $this->tempDir.'/test-skill';
        mkdir($skillPath, 0755, true);

        $skillMd = <<<'YAML'
---
name: test-skill
description: A test skill for validation
---
# Test Skill

This is a test skill.
YAML;

        file_put_contents($skillPath.'/SKILL.md', $skillMd);

        $result = $this->validator->validateSkill($skillPath);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
        $this->assertArrayHasKey('metadata', $result);
        $this->assertEquals('test-skill', $result['metadata']['name']);
    }

    /**
     * Test validation fails when SKILL.md is missing.
     */
    public function testMissingSkillMd(): void
    {
        $skillPath = $this->tempDir.'/test-skill';
        mkdir($skillPath, 0755, true);

        $result = $this->validator->validateSkill($skillPath);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('SKILL.md', implode(' ', $result['errors']));
    }

    /**
     * Test validation fails when name doesn't match directory.
     */
    public function testNameMismatch(): void
    {
        $skillPath = $this->tempDir.'/test-skill';
        mkdir($skillPath, 0755, true);

        $skillMd = <<<'YAML'
---
name: different-name
description: A test skill
---
YAML;

        file_put_contents($skillPath.'/SKILL.md', $skillMd);

        $result = $this->validator->validateSkill($skillPath);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $errorString = implode(' ', $result['errors']);
        $this->assertTrue(
            false !== strpos($errorString, 'must match') || false !== strpos($errorString, 'match directory name'),
            "Expected error message about directory name mismatch, got: {$errorString}"
        );
    }

    /**
     * Test validation fails when name is invalid.
     */
    public function testInvalidName(): void
    {
        $skillPath = $this->tempDir.'/Test-Skill';
        mkdir($skillPath, 0755, true);

        $skillMd = <<<'YAML'
---
name: Test-Skill
description: A test skill
---
YAML;

        file_put_contents($skillPath.'/SKILL.md', $skillMd);

        $result = $this->validator->validateSkill($skillPath);

        // Should fail because name contains uppercase
        $this->assertFalse($result['valid']);
    }

    /**
     * Test validation fails when description is missing.
     */
    public function testMissingDescription(): void
    {
        $skillPath = $this->tempDir.'/test-skill';
        mkdir($skillPath, 0755, true);

        $skillMd = <<<'YAML'
---
name: test-skill
---
YAML;

        file_put_contents($skillPath.'/SKILL.md', $skillMd);

        $result = $this->validator->validateSkill($skillPath);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('description', implode(' ', $result['errors']));
    }

    /**
     * Test validation fails when name starts with hyphen.
     */
    public function testNameStartsWithHyphen(): void
    {
        $skillPath = $this->tempDir.'/-test-skill';
        mkdir($skillPath, 0755, true);

        $skillMd = <<<'YAML'
---
name: -test-skill
description: A test skill
---
YAML;

        file_put_contents($skillPath.'/SKILL.md', $skillMd);

        $result = $this->validator->validateSkill($skillPath);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('start or end with a hyphen', implode(' ', $result['errors']));
    }

    /**
     * Test validation fails when name ends with hyphen.
     */
    public function testNameEndsWithHyphen(): void
    {
        $skillPath = $this->tempDir.'/test-skill-';
        mkdir($skillPath, 0755, true);

        $skillMd = <<<'YAML'
---
name: test-skill-
description: A test skill
---
YAML;

        file_put_contents($skillPath.'/SKILL.md', $skillMd);

        $result = $this->validator->validateSkill($skillPath);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('start or end with a hyphen', implode(' ', $result['errors']));
    }

    /**
     * Test validation fails when name contains consecutive hyphens.
     */
    public function testNameConsecutiveHyphens(): void
    {
        $skillPath = $this->tempDir.'/test--skill';
        mkdir($skillPath, 0755, true);

        $skillMd = <<<'YAML'
---
name: test--skill
description: A test skill
---
YAML;

        file_put_contents($skillPath.'/SKILL.md', $skillMd);

        $result = $this->validator->validateSkill($skillPath);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('consecutive hyphens', implode(' ', $result['errors']));
    }

    /**
     * Test validation fails when name is too long.
     */
    public function testNameTooLong(): void
    {
        $longName = str_repeat('a', 65);
        $skillPath = $this->tempDir.'/'.$longName;
        mkdir($skillPath, 0755, true);

        $skillMd = <<<YAML
---
name: {$longName}
description: A test skill
---
YAML;

        file_put_contents($skillPath.'/SKILL.md', $skillMd);

        $result = $this->validator->validateSkill($skillPath);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('exceeds', implode(' ', $result['errors']));
        $this->assertStringContainsString('64', implode(' ', $result['errors']));
    }

    /**
     * Test validation fails when description is empty.
     */
    public function testDescriptionEmpty(): void
    {
        $skillPath = $this->tempDir.'/test-skill';
        mkdir($skillPath, 0755, true);

        $skillMd = <<<'YAML'
---
name: test-skill
description: 
---
YAML;

        file_put_contents($skillPath.'/SKILL.md', $skillMd);

        $result = $this->validator->validateSkill($skillPath);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('description', implode(' ', $result['errors']));
    }

    /**
     * Test validation fails when description is too long.
     */
    public function testDescriptionTooLong(): void
    {
        $skillPath = $this->tempDir.'/test-skill';
        mkdir($skillPath, 0755, true);

        $longDescription = str_repeat('a', 1025);
        $skillMd = <<<YAML
---
name: test-skill
description: {$longDescription}
---
YAML;

        file_put_contents($skillPath.'/SKILL.md', $skillMd);

        $result = $this->validator->validateSkill($skillPath);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('exceeds', implode(' ', $result['errors']));
        $this->assertStringContainsString('1024', implode(' ', $result['errors']));
    }

    /**
     * Test validation fails when compatibility is too long.
     */
    public function testCompatibilityTooLong(): void
    {
        $skillPath = $this->tempDir.'/test-skill';
        mkdir($skillPath, 0755, true);

        $longCompatibility = str_repeat('a', 501);
        $skillMd = <<<YAML
---
name: test-skill
description: A test skill
compatibility: {$longCompatibility}
---
YAML;

        file_put_contents($skillPath.'/SKILL.md', $skillMd);

        $result = $this->validator->validateSkill($skillPath);

        $this->assertFalse($result['valid']);
        $errorString = strtolower(implode(' ', $result['errors']));
        $this->assertStringContainsString('compatibility', $errorString);
        $this->assertStringContainsString('exceeds', $errorString);
        $this->assertStringContainsString('500', $errorString);
    }

    /**
     * Test validation fails when unexpected fields are present.
     */
    public function testUnexpectedFields(): void
    {
        $skillPath = $this->tempDir.'/test-skill';
        mkdir($skillPath, 0755, true);

        $skillMd = <<<'YAML'
---
name: test-skill
description: A test skill
invalid-field: should not be here
---
YAML;

        file_put_contents($skillPath.'/SKILL.md', $skillMd);

        $result = $this->validator->validateSkill($skillPath);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Unexpected fields', implode(' ', $result['errors']));
        $this->assertStringContainsString('invalid-field', implode(' ', $result['errors']));
    }

    /**
     * Test validation passes with all optional fields.
     */
    public function testValidSkillWithOptionalFields(): void
    {
        $skillPath = $this->tempDir.'/test-skill';
        mkdir($skillPath, 0755, true);

        $skillMd = <<<'YAML'
---
name: test-skill
description: A test skill
license: MIT
compatibility: Requires PHP 8.1+
metadata:
  author: test-author
  version: "1.0"
---
YAML;

        file_put_contents($skillPath.'/SKILL.md', $skillMd);

        $result = $this->validator->validateSkill($skillPath);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
        $this->assertSame('Requires PHP 8.1+', $result['metadata']['compatibility']);
        $this->assertSame(['author' => 'test-author', 'version' => '1.0'], $result['metadata']['metadata']);
    }

    /**
     * A plain multi-line scalar is one string, as in drupal-canvas/skills.
     */
    public function testPlainMultiLineDescription(): void
    {
        $skillPath = $this->tempDir.'/test-skill';
        mkdir($skillPath, 0755, true);

        $skillMd = <<<'YAML'
---
name: test-skill
description:
  Use when maintaining legacy regions or a
  project-level Region-based layout.
---
# Test Skill
YAML;

        file_put_contents($skillPath.'/SKILL.md', $skillMd);

        $result = $this->validator->validateSkill($skillPath);

        $this->assertTrue($result['valid'], implode(' ', $result['errors']));
        $this->assertSame(
            'Use when maintaining legacy regions or a project-level Region-based layout.',
            $result['metadata']['description']
        );
    }

    /**
     * A folded block scalar (>-) loses its indicator and line breaks.
     */
    public function testFoldedBlockScalarDescription(): void
    {
        $skillPath = $this->tempDir.'/test-skill';
        mkdir($skillPath, 0755, true);

        $skillMd = <<<'YAML'
---
name: test-skill
description: >-
  Use when working in a
  headless codebase.
---
YAML;

        file_put_contents($skillPath.'/SKILL.md', $skillMd);

        $result = $this->validator->validateSkill($skillPath);

        $this->assertTrue($result['valid'], implode(' ', $result['errors']));
        $this->assertSame('Use when working in a headless codebase.', $result['metadata']['description']);
    }

    /**
     * A literal block scalar (|) loses its indicator and keeps line breaks.
     */
    public function testLiteralBlockScalarDescription(): void
    {
        $skillPath = $this->tempDir.'/test-skill';
        mkdir($skillPath, 0755, true);

        $skillMd = <<<'YAML'
---
name: test-skill
description: |
  Analyze things.
  Second line.
---
YAML;

        file_put_contents($skillPath.'/SKILL.md', $skillMd);

        $result = $this->validator->validateSkill($skillPath);

        $this->assertTrue($result['valid'], implode(' ', $result['errors']));
        $this->assertSame("Analyze things.\nSecond line.", $result['metadata']['description']);
    }

    /**
     * A quoted description may contain a colon, and escapes are resolved.
     */
    public function testQuotedDescriptionWithColonAndEscapes(): void
    {
        $skillPath = $this->tempDir.'/test-skill';
        mkdir($skillPath, 0755, true);

        $skillMd = <<<'YAML'
---
name: test-skill
description: "Use when: the book \"Drupal at Your Fingertips\" applies"
---
YAML;

        file_put_contents($skillPath.'/SKILL.md', $skillMd);

        $result = $this->validator->validateSkill($skillPath);

        $this->assertTrue($result['valid'], implode(' ', $result['errors']));
        $this->assertSame('Use when: the book "Drupal at Your Fingertips" applies', $result['metadata']['description']);
    }

    /**
     * An unquoted colon in a plain scalar is not valid YAML; report it, do not crash.
     */
    public function testUnquotedColonInDescriptionIsInvalidYaml(): void
    {
        $skillPath = $this->tempDir.'/test-skill';
        mkdir($skillPath, 0755, true);

        $skillMd = <<<'YAML'
---
name: test-skill
description: Use when: something happens
---
YAML;

        file_put_contents($skillPath.'/SKILL.md', $skillMd);

        $result = $this->validator->validateSkill($skillPath);

        $this->assertFalse($result['valid']);
        $errorString = implode(' ', $result['errors']);
        $this->assertStringContainsString('Invalid YAML frontmatter', $errorString);
        $this->assertStringContainsString('line 2', $errorString);
    }

    /**
     * Frontmatter that is valid YAML but not a mapping is rejected with a clear message.
     */
    public function testFrontmatterMustBeAMapping(): void
    {
        $skillPath = $this->tempDir.'/test-skill';
        mkdir($skillPath, 0755, true);

        $skillMd = <<<'YAML'
---
- test-skill
- A test skill
---
YAML;

        file_put_contents($skillPath.'/SKILL.md', $skillMd);

        $result = $this->validator->validateSkill($skillPath);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('must be a mapping', implode(' ', $result['errors']));
    }

    /**
     * A file without a frontmatter block is reported as such.
     */
    public function testMissingFrontmatterBlock(): void
    {
        $skillPath = $this->tempDir.'/test-skill';
        mkdir($skillPath, 0755, true);

        file_put_contents($skillPath.'/SKILL.md', "# Test Skill\n\nNo frontmatter here.\n");

        $result = $this->validator->validateSkill($skillPath);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Invalid YAML frontmatter', implode(' ', $result['errors']));
    }

    /**
     * Block and flow sequences both parse to arrays.
     */
    public function testAllowedToolsSequences(): void
    {
        $skillPath = $this->tempDir.'/test-skill';
        mkdir($skillPath, 0755, true);

        $skillMd = <<<'YAML'
---
name: test-skill
description: A test skill
allowed-tools:
  - Read
  - Grep
---
YAML;

        file_put_contents($skillPath.'/SKILL.md', $skillMd);
        $result = $this->validator->validateSkill($skillPath);
        $this->assertTrue($result['valid'], implode(' ', $result['errors']));
        $this->assertSame(['Read', 'Grep'], $result['metadata']['allowed-tools']);

        $skillMd = <<<'YAML'
---
name: test-skill
description: A test skill
allowed-tools: [Read, Grep]
---
YAML;

        file_put_contents($skillPath.'/SKILL.md', $skillMd);
        $result = $this->validator->validateSkill($skillPath);
        $this->assertTrue($result['valid'], implode(' ', $result['errors']));
        $this->assertSame(['Read', 'Grep'], $result['metadata']['allowed-tools']);
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
