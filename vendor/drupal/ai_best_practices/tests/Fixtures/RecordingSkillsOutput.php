<?php

namespace Drupal\AiBestPractices\Tests\Fixtures;

use Drupal\AiBestPractices\Skills\SkillsOutputStyleInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Test double for skills scan/install output (SkillsOutputStyleInterface).
 */
final class RecordingSkillsOutput implements SkillsOutputStyleInterface
{
    /**
     * @var list<array{type: string, message: string}>
     */
    public array $entries = [];

    public function info(string|array $message): void
    {
        foreach (\is_array($message) ? $message : [$message] as $line) {
            $this->entries[] = ['type' => 'info', 'message' => $line];
        }
    }

    public function warning(string|array $message): void
    {
        foreach (\is_array($message) ? $message : [$message] as $line) {
            $this->entries[] = ['type' => 'warning', 'message' => $line];
        }
    }

    public function error(string|array $message): void
    {
        foreach (\is_array($message) ? $message : [$message] as $line) {
            $this->entries[] = ['type' => 'error', 'message' => $line];
        }
    }

    public function writeln(string|iterable $messages, int $type = OutputInterface::OUTPUT_NORMAL): void
    {
        if (\is_string($messages)) {
            $this->entries[] = ['type' => 'writeln', 'message' => $messages];

            return;
        }
        foreach ($messages as $line) {
            $this->entries[] = ['type' => 'writeln', 'message' => (string) $line];
        }
    }
}
