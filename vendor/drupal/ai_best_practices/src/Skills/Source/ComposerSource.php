<?php

declare(strict_types=1);

namespace Drupal\AiBestPractices\Skills\Source;

/**
 * Composer package skill source (handled by SkillsScanner).
 *
 * This is a placeholder - actual Composer scanning is done in SkillsScanner.
 */
class ComposerSource implements SkillSourceInterface
{
    public function getSkills(): array
    {
        // Composer skills are discovered by SkillsScanner during recursive scan.
        return [];
    }

    public function getSourceType(): string
    {
        return 'composer';
    }
}
