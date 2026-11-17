<?php

declare(strict_types=1);

namespace Drupal\AiBestPractices\Skills\Command;

use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * SymfonyStyle that suppresses info/warning/note for machine-readable output (e.g. XML).
 */
final class QuietSymfonyStyle extends SymfonyStyle
{
    public function info($message): void
    {
    }

    public function warning($message): void
    {
    }

    public function note($message): void
    {
    }
}
