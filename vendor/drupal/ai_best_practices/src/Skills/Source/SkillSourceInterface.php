<?php

declare(strict_types=1);

namespace Drupal\AiBestPractices\Skills\Source;

/**
 * Interface for skill sources.
 */
interface SkillSourceInterface
{
    /**
     * Get skills from this source.
     *
     * @return array
     *               Array of skills keyed by skill name, each with 'path', 'name', 'source_type', 'source_name'
     */
    public function getSkills(): array;

    /**
     * Get the source type identifier.
     *
     * @return string
     *                Source type ('git', 'local', etc.).
     */
    public function getSourceType(): string;
}
