<?php

declare(strict_types=1);

namespace Drupal\AiBestPractices\Composer;

use Composer\IO\IOInterface;
use Drupal\AiBestPractices\Agents\AgentRedirectFiles;
use Drupal\AiBestPractices\Agents\AgentsMdSync;
use Drupal\AiBestPractices\Agents\MalformedAgentsMdException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Writes consumer PROJECT_ROOT/AGENTS.md from the package template and adds
 * CLAUDE.md / GEMINI.md when missing.
 */
final class AgentsProjectSync
{
    public static function run(string $projectRoot, string $packageRoot, IOInterface $io): int
    {
        $filesystem = new Filesystem();
        $sync = new AgentsMdSync($filesystem);

        try {
            $written = $sync->sync($projectRoot, $packageRoot);
            if ($written) {
                $io->write('<info>drupal/ai_best_practices: updated AGENTS.md</info>');
            }
        } catch (MalformedAgentsMdException $e) {
            $io->writeError('<error>drupal/ai_best_practices: '.$e->getMessage().'</error>');

            return 1;
        } catch (\Throwable $e) {
            $io->writeError('<error>drupal/ai_best_practices: '.$e->getMessage().'</error>');

            return 1;
        }

        try {
            AgentRedirectFiles::sync($projectRoot, $packageRoot, $filesystem, $io);
        } catch (\Throwable $e) {
            $io->writeError('<error>drupal/ai_best_practices: '.$e->getMessage().'</error>');

            return 1;
        }

        return 0;
    }
}
