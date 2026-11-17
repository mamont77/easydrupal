<?php

declare(strict_types=1);

namespace Drupal\AiBestPractices\Skills\Command;

use Drupal\AiBestPractices\PathSecurity;
use Drupal\AiBestPractices\ProjectRootResolver;
use Drupal\AiBestPractices\Skills\SkillsConfig;
use Drupal\AiBestPractices\Skills\SkillsScanner;
use Drupal\AiBestPractices\Skills\SkillValidator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command to list available Agent Skills.
 */
class SkillsListCommand extends Command
{
    /**
     * Configures the command.
     */
    protected function configure(): void
    {
        $this->setName('drupal-ai:skills-list')
          ->setDescription('List all available Agent Skills from configured sources')
          ->addOption(
              'format',
              'f',
              InputOption::VALUE_REQUIRED,
              'Output format: table (default) or xml (for file-based agents)',
              'table'
          )
          ->setHelp('This command lists all Agent Skills that would be synced, showing their source and validation status.

Use this to verify your skill configuration before running skills-sync.

Output formats:
  table   Human-readable table format (default)
  xml     XML format for file-based agents, includes skill paths');
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
        $format = $input->getOption('format');

        try {
            $projectRoot = ProjectRootResolver::resolve();
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        // Validate format option.
        if (!in_array($format, ['table', 'xml'], true)) {
            $io->error("Invalid format '{$format}'. Supported formats: table, xml");

            return Command::FAILURE;
        }

        // For XML format, use a quieter IO to suppress scanning messages.
        $scanIo = 'xml' === $format ? new QuietSymfonyStyle($input, $output) : $io;

        if ('table' === $format) {
            $io->title('Available Agent Skills');
        }

        try {
            // Check if skills are enabled. This reads ai_best_practices.yaml and will throw
            // \InvalidArgumentException when the file exists but is unreadable or invalid.
            if (!SkillsConfig::isEnabled($projectRoot)) {
                if ('xml' === $format) {
                    $output->writeln('<available_skills>');
                    $output->writeln('</available_skills>');
                } else {
                    $io->warning('Skills are disabled. Enable them in ai_best_practices.yaml by setting skills.enabled: true');
                }

                return Command::SUCCESS;
            }

            $validator = new SkillValidator();
            $allSkills = [];

            // Scan skills from recursive discovery.
            $scanner = new SkillsScanner($projectRoot);
            $scannedSkills = $scanner->scanAllSkills($scanIo);
            foreach ($scannedSkills as $skillName => $skillInfo) {
                $validation = $validator->validateSkill($skillInfo['path']);
                $allSkills[$skillName] = [
                    'info' => $skillInfo,
                    'valid' => $validation['valid'],
                    'metadata' => $validation['metadata'] ?? [],
                ];
            }

            if (empty($allSkills)) {
                if ('xml' === $format) {
                    $output->writeln('<available_skills>');
                    $output->writeln('</available_skills>');
                } else {
                    $io->info('No skills found.');
                }

                return Command::SUCCESS;
            }

            // Output based on format.
            if ('xml' === $format) {
                $this->outputXml($output, $allSkills);
            } else {
                $this->outputTable($io, $allSkills);
            }

            return Command::SUCCESS;
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * Output skills in table format.
     *
     * @param SymfonyStyle $io
     *                                The IO style helper
     * @param array        $allSkills
     *                                Array of all skills with their info and metadata
     */
    private function outputTable(SymfonyStyle $io, array $allSkills): void
    {
        $rows = [];
        foreach ($allSkills as $skillName => $skillData) {
            $info = $skillData['info'];
            $metadata = $skillData['metadata'];
            $description = $metadata['description'] ?? null;
            if (!is_string($description) || '' === trim($description)) {
                $description = '(no description)';
            }
            // Block scalars may span lines; keep the table to one row per skill.
            $description = preg_replace('/\s+/u', ' ', trim($description));
            if (mb_strlen($description, 'UTF-8') > 60) {
                $description = mb_substr($description, 0, 57, 'UTF-8').'...';
            }

            $status = $skillData['valid'] ? 'Valid' : 'Invalid';
            $source = $info['source_type'].($info['source_name'] ? ' ('.$info['source_name'].')' : '');

            $rows[] = [
                $skillName,
                $description,
                $source,
                $status,
            ];
        }

        $io->table(
            ['Name', 'Description', 'Source', 'Status'],
            $rows
        );

        $validCount = count(array_filter($allSkills, fn ($s) => $s['valid']));
        $io->info('Found '.count($allSkills)." skill(s), {$validCount} valid");
    }

    /**
     * Output skills in XML format for file-based agents.
     *
     * @param OutputInterface $output
     *                                   The output interface
     * @param array           $allSkills
     *                                   Array of all skills with their info and metadata
     */
    private function outputXml(OutputInterface $output, array $allSkills): void
    {
        $output->writeln('<available_skills>');

        foreach ($allSkills as $skillName => $skillData) {
            // Only include valid skills in XML output.
            if (!$skillData['valid']) {
                continue;
            }

            $info = $skillData['info'];
            $metadata = $skillData['metadata'];
            $description = $metadata['description'] ?? '';
            if (!is_string($description)) {
                $description = '';
            }
            $skillMdPath = PathSecurity::joinPaths($info['path'], SkillsConfig::SKILL_MD_FILENAME);

            $output->writeln('  <skill>');
            $output->writeln('    <name>'.$this->escapeXml($skillName).'</name>');
            $output->writeln('    <description>'.$this->escapeXml($description).'</description>');
            $output->writeln('    <location>'.$this->escapeXml($skillMdPath).'</location>');
            $output->writeln('  </skill>');
        }

        $output->writeln('</available_skills>');
    }

    /**
     * Escape special XML characters.
     *
     * @param string $string
     *                       The string to escape
     *
     * @return string
     *                The escaped string
     */
    private function escapeXml(string $string): string
    {
        return htmlspecialchars($string, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
