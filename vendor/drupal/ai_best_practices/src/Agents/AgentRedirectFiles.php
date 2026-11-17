<?php

declare(strict_types=1);

namespace Drupal\AiBestPractices\Agents;

use Composer\IO\IOInterface;
use Drupal\AiBestPractices\PathSecurity;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Installs thin redirect files (CLAUDE.md, GEMINI.md) at the consumer project root.
 */
final class AgentRedirectFiles
{
    /** @var array<string, string> destination basename => path inside package relative to package root */
    private const FILES = [
        'CLAUDE.md' => '.agents/CLAUDE.md',
        'GEMINI.md' => '.agents/GEMINI.md',
    ];

    public static function sync(
        string $projectRoot,
        string $packageRoot,
        Filesystem $filesystem,
        ?IOInterface $io,
    ): void {
        $projectRoot = PathSecurity::joinPaths($projectRoot);
        $packageRoot = PathSecurity::joinPaths($packageRoot);

        foreach (self::FILES as $basename => $relativeSource) {
            $destination = PathSecurity::joinPaths($projectRoot, $basename);
            $source = PathSecurity::joinPaths($packageRoot, $relativeSource);

            if (!PathSecurity::isPathWithinBase($destination, $projectRoot)) {
                throw new \InvalidArgumentException('Destination escapes project root: '.$destination);
            }

            if (!$filesystem->exists($source)) {
                throw new \RuntimeException('Missing package source at '.$source);
            }

            if ($filesystem->exists($destination)) {
                if (null !== $io) {
                    $io->writeError(sprintf(
                        '<warning>drupal/ai_best_practices: %s already exists at project root; left unchanged. Point it at AGENTS.md so tools load the canonical Drupal AI context from this package (see vendor/drupal/ai_best_practices/.agents/SETUP.md).</warning>',
                        $basename
                    ));
                }

                continue;
            }

            $body = str_replace("\r\n", "\n", (string) file_get_contents($source));
            $filesystem->dumpFile($destination, $body);
            if (null !== $io) {
                $io->write(sprintf('<info>drupal/ai_best_practices: added %s</info>', $basename));
            }
        }
    }
}
