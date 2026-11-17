<?php

declare(strict_types=1);

namespace Drupal\AiBestPractices\Tests\AiBestPractices\Console;

use Drupal\AiBestPractices\Console\AiBestPracticesApplication;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AiBestPracticesApplication.
 */
class AiBestPracticesApplicationTest extends TestCase
{
    public function testConstructorRegistersSkillsCommands(): void
    {
        $application = new AiBestPracticesApplication();

        $this->assertTrue($application->has('drupal-ai:skills-sync'));
        $this->assertTrue($application->has('drupal-ai:skills-install'));
        $this->assertTrue($application->has('drupal-ai:skills-list'));
    }
}
