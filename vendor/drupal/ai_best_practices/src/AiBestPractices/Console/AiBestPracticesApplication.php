<?php

declare(strict_types=1);

namespace Drupal\AiBestPractices\Console;

use Drupal\AiBestPractices\Skills\Command\SkillsListCommand;
use Drupal\AiBestPractices\Skills\Command\SkillsSyncCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Main AI Best Practices console application (Composer script {@see self::COMPOSER_SCRIPT_NAME}).
 */
class AiBestPracticesApplication extends Application
{
    public const COMPOSER_SCRIPT_NAME = 'drupal-ai';

    /**
     * Callback string stored in consumer composer.json `scripts` entries.
     */
    public const COMPOSER_SCRIPT_CALLBACK = 'Drupal\\AiBestPractices\\Console\\AiBestPracticesApplication::runFromComposer';

    public function __construct()
    {
        parent::__construct('Drupal AI Best Practices', '1.0.0');

        foreach ([new SkillsSyncCommand(), new SkillsListCommand()] as $command) {
            // Symfony Console 8 removed add() in favor of addCommand(), which
            // 7.4 introduced. Inside Composer the Console bundled in its phar
            // (5.4) has only add(), so use whichever this version provides.
            if (method_exists($this, 'addCommand')) {
                $this->addCommand($command);
            } else {
                $this->add($command);
            }
        }
    }

    /**
     * Called from Composer script: composer drupal-ai <subcommand> (legacy: composer abp).
     *
     * @return int
     *             The exit code
     */
    public static function runFromComposer(): int
    {
        $app = new self();

        $argv = $_SERVER['argv'] ?? [];
        $scriptIndex = self::findComposerScriptIndex($argv);

        if (false === $scriptIndex || !isset($argv[$scriptIndex + 1])) {
            $output = new ConsoleOutput();
            $name = self::COMPOSER_SCRIPT_NAME;
            self::renderScriptUsage($output, "<error>Missing subcommand. Usage: composer {$name} <subcommand></error>");

            return 1;
        }

        $subcommand = $argv[$scriptIndex + 1];

        $commandMap = [
            'skills-sync' => 'drupal-ai:skills-sync',
            'skills-install' => 'drupal-ai:skills-sync',
            'skills-list' => 'drupal-ai:skills-list',
        ];

        $commandName = $commandMap[$subcommand] ?? null;

        if (!$commandName) {
            $output = new ConsoleOutput();
            self::renderScriptUsage($output, "<error>Unknown subcommand: {$subcommand}</error>");

            return 1;
        }

        $inputArgs = ['command' => $commandName];
        $remainingArgs = array_slice($argv, $scriptIndex + 2);

        foreach ($remainingArgs as $arg) {
            if (preg_match('/^--([^=]+)=(.*)$/', $arg, $matches)) {
                $inputArgs['--'.$matches[1]] = $matches[2];
            } elseif (preg_match('/^--(.+)$/', $arg, $matches)) {
                $inputArgs['--'.$matches[1]] = true;
            } elseif (preg_match('/^-([a-zA-Z])=(.*)$/', $arg, $matches)) {
                $inputArgs['-'.$matches[1]] = $matches[2];
            } elseif (preg_match('/^-([a-zA-Z])$/', $arg, $matches)) {
                $inputArgs['-'.$matches[1]] = true;
            } else {
                $keys = array_keys($inputArgs);
                $lastKey = end($keys);
                if (false !== $lastKey && true === $inputArgs[$lastKey] && str_starts_with($lastKey, '-')) {
                    $inputArgs[$lastKey] = $arg;
                }
            }
        }

        $input = new ArrayInput($inputArgs);
        $output = new ConsoleOutput();

        return $app->run($input, $output);
    }

    /**
     * @param array<int, string> $argv
     *
     * @return false|int Index of the script name token in argv
     */
    private static function findComposerScriptIndex(array $argv): false|int
    {
        foreach ([self::COMPOSER_SCRIPT_NAME, 'abp'] as $name) {
            $idx = array_search($name, $argv, true);
            if (false !== $idx) {
                return $idx;
            }
        }

        return false;
    }

    /**
     * Prints the error line then standard `composer drupal-ai` subcommand list.
     */
    private static function renderScriptUsage(ConsoleOutput $output, string $errorLine): void
    {
        $output->writeln($errorLine);
        $output->writeln('<info>Available subcommands:</info>');
        $output->writeln('  skills-sync     Sync Agent Skills into skills.output_directory (alias: skills-install)');
        $output->writeln('  skills-list     List available Agent Skills');
    }
}
