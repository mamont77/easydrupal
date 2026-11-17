<?php

declare(strict_types=1);

namespace Drupal\AiBestPractices\Skills;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Minimal console output surface for skills sync / scan.
 *
 * Implemented by test doubles; SymfonyStyle is accepted via union types.
 */
interface SkillsOutputStyleInterface
{
    /**
     * Formats an info message.
     */
    public function info(string|array $message): void;

    /**
     * Formats a warning message.
     */
    public function warning(string|array $message): void;

    /**
     * Formats an error message.
     */
    public function error(string|array $message): void;

    /**
     * Writes one or more lines to the output.
     */
    public function writeln(string|iterable $messages, int $type = OutputInterface::OUTPUT_NORMAL): void;
}
