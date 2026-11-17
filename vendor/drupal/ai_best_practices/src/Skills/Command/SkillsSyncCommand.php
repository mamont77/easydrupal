<?php

declare(strict_types=1);

namespace Drupal\AiBestPractices\Skills\Command;

use Drupal\AiBestPractices\ProjectRootResolver;
use Drupal\AiBestPractices\Skills\SkillsAggregator;
use Drupal\AiBestPractices\Skills\SkillsConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command to sync Agent Skills from configured sources into the output directory.
 */
class SkillsSyncCommand extends Command
{
    /**
     * Configures the command.
     */
    protected function configure(): void
    {
        $this->setName('drupal-ai:skills-sync')
          ->setAliases(['drupal-ai:skills-install'])
          ->setDescription('Sync Agent Skills from all configured sources into skills.output_directory')
          ->setHelp(
              'Merge skills from project, modules, and Composer packages into skills.output_directory '.
              '(see https://agentskills.io/specification). '.
              'Configure sources in ai_best_practices.yaml; see ai_best_practices.yaml.dist and README.md.'
          );
    }

    /**
     * Executes the command.
     *
     * @param InputInterface  $input
     *                                The input interface
     * @param OutputInterface $output
     *                                The output interface
     *
     * @return int
     *             The command exit code
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $projectRoot = ProjectRootResolver::resolve();
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->title('Syncing Agent Skills');

        try {
            // Check if skills are enabled (they are by default, but can be disabled in ai_best_practices.yaml).
            // This call reads ai_best_practices.yaml and will throw \InvalidArgumentException when
            // the file exists but is unreadable or contains invalid YAML.
            if (!SkillsConfig::isEnabled($projectRoot)) {
                $io->warning('Skills are disabled. Enable them in ai_best_practices.yaml:');
                $io->writeln('');
                $io->writeln('  skills:');
                $io->writeln('    enabled: true');
                $io->writeln('');

                return Command::SUCCESS;
            }

            $aggregator = new SkillsAggregator($projectRoot);
            $result = $aggregator->aggregate($io);

            if ($result['success']) {
                $outputDir = SkillsConfig::getOutputSkillsDirectory($projectRoot);
                $io->success("Synced {$result['installed']} skill(s) to {$outputDir}/");

                if (!empty($result['errors'])) {
                    $io->warning('Some skills had errors:');
                    foreach ($result['errors'] as $error) {
                        $io->writeln("  • {$error}");
                    }
                }

                $io->writeln('');
                $io->writeln('<comment>Learn more: https://agentskills.io/</comment>');

                return Command::SUCCESS;
            }

            $io->error('Failed to sync skills');
            foreach ($result['errors'] as $error) {
                $io->writeln("  • {$error}");
            }

            return Command::FAILURE;
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }
}
