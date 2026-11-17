<?php

declare(strict_types=1);

namespace Drupal\AiBestPractices\Composer;

use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\Installer\PackageEvent;
use Composer\IO\IOInterface;
use Drupal\AiBestPractices\Console\AiBestPracticesApplication;
use Drupal\AiBestPractices\PackageRoot;
use Drupal\AiBestPractices\PathSecurity;
use Drupal\AiBestPractices\ProjectRootResolver;
use Drupal\AiBestPractices\Skills\SkillsConfig;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

/**
 * Coordinates skills sync and AGENTS.md / redirect sync when this package is installed or updated.
 *
 * Composer fires POST_PACKAGE_INSTALL / POST_PACKAGE_UPDATE before autoload files are
 * dumped, so heavy work is deferred to post-install-cmd / post-update-cmd via a marker
 * file under vendor/.
 */
final class ComposerProjectSynchronizer
{
    public const PACKAGE_NAME = 'drupal/ai_best_practices';

    /**
     * Relative path under the project root; touched when this package is installed/updated.
     */
    private const SYNC_PENDING_MARKER = 'vendor/.ai_bp_sync_pending';

    /**
     * Root-anchored .gitignore pattern for the per-developer configuration file.
     */
    public const CONFIG_GITIGNORE_ENTRY = '/ai_best_practices.yaml';

    /**
     * Records that deferred sync should run after Composer dumps autoload.
     *
     * Called from POST_PACKAGE_INSTALL / POST_PACKAGE_UPDATE only for this package.
     */
    public static function markSyncPendingFromPackageEvent(PackageEvent $event): void
    {
        if (self::skipSyncDueToEnv()) {
            return;
        }

        $operation = $event->getOperation();
        if ($operation instanceof InstallOperation) {
            $package = $operation->getPackage();
        } elseif ($operation instanceof UpdateOperation) {
            $package = $operation->getTargetPackage();
        } else {
            return;
        }

        if (self::PACKAGE_NAME !== $package->getName()) {
            return;
        }

        try {
            $projectRoot = ProjectRootResolver::resolve();
        } catch (\RuntimeException $e) {
            return;
        }

        if (PackageRoot::isPackageOwnTree($projectRoot)) {
            return;
        }

        $composerJsonPath = Path::join($projectRoot, 'composer.json');
        $filesystem = new Filesystem();
        if (!$filesystem->exists($composerJsonPath)) {
            return;
        }

        $marker = Path::join($projectRoot, self::SYNC_PENDING_MARKER);
        try {
            $filesystem->mkdir(Path::getDirectory($marker));
            $filesystem->dumpFile($marker, (string) time());
        } catch (\Exception $e) {
            $event->getIO()->writeError('<warning>AI Best Practices: could not schedule sync: '.$e->getMessage().'</warning>');
        }
    }

    /**
     * Runs deferred sync when a marker exists (after autoload dump).
     */
    public static function runDeferredSyncIfPending(string $projectRoot, IOInterface $io): void
    {
        if (self::skipSyncDueToEnv()) {
            self::clearPendingMarker($projectRoot);

            return;
        }

        $marker = Path::join($projectRoot, self::SYNC_PENDING_MARKER);
        if (!is_file($marker)) {
            return;
        }

        $packageDir = PackageRoot::path();
        $filesystem = new Filesystem();
        self::ensureDefaultConfig($projectRoot, $packageDir, $filesystem, $io);

        $exit = self::runSyncCli($io);
        self::clearPendingMarker($projectRoot);
        if (0 !== $exit) {
            $io->writeError('<warning>AI Best Practices sync finished with exit code '.$exit.'</warning>');
        }
    }

    private static function clearPendingMarker(string $projectRoot): void
    {
        $marker = Path::join($projectRoot, self::SYNC_PENDING_MARKER);
        if (is_file($marker)) {
            @unlink($marker);
        }
    }

    /**
     * Removes the deferred-sync marker (e.g. on package uninstall).
     */
    public static function removeSyncPendingMarker(string $projectRoot): void
    {
        self::clearPendingMarker($projectRoot);
    }

    /**
     * Ensures ai_best_practices.yaml exists from the package template (same as plugin registration).
     *
     * The file holds per-developer settings (output directory, excluded skills), so when
     * it is first created it is also added to the project's .gitignore.
     */
    public static function ensureDefaultConfig(
        string $projectRoot,
        string $packageDir,
        Filesystem $filesystem,
        IOInterface $io,
    ): void {
        $configPath = Path::join($projectRoot, 'ai_best_practices.yaml');

        if ($filesystem->exists($configPath)) {
            return;
        }

        $templatePath = Path::join($packageDir, 'ai_best_practices.yaml.dist');
        if (!$filesystem->exists($templatePath)) {
            $vendorTemplatePath = Path::join($projectRoot, 'vendor', 'drupal', 'ai_best_practices', 'ai_best_practices.yaml.dist');
            if ($filesystem->exists($vendorTemplatePath)) {
                $templatePath = $vendorTemplatePath;
            } else {
                return;
            }
        }

        try {
            $template = file_get_contents($templatePath);
            if (false === $template) {
                throw new \RuntimeException(sprintf('Could not read %s.', $templatePath));
            }
            $outputDirectory = SkillsConfig::detectDefaultOutputDirectory($projectRoot);
            $filesystem->dumpFile($configPath, self::withOutputDirectory($template, $outputDirectory));
            $io->write(sprintf('<info>Created ai_best_practices.yaml with defaults (skills output: %s; edit to customize)</info>', $outputDirectory));
        } catch (\Exception $e) {
            $io->writeError('<warning>Could not create ai_best_practices.yaml: '.$e->getMessage().'</warning>');

            return;
        }

        self::ensureConfigGitIgnored($projectRoot, $filesystem, $io);
    }

    /**
     * Adds ai_best_practices.yaml to the project's .gitignore unless it already decides about it.
     *
     * An existing entry for the file, including a `!` negation, is left as the project's choice.
     * A .gitignore that is a symlink or not a regular file is never written to.
     */
    private static function ensureConfigGitIgnored(string $projectRoot, Filesystem $filesystem, IOInterface $io): void
    {
        $gitignorePath = Path::join($projectRoot, '.gitignore');
        $manualHint = '<warning>AI Best Practices: add '.self::CONFIG_GITIGNORE_ENTRY.' to .gitignore yourself; the file holds per-developer settings.</warning>';

        $contents = '';
        if ($filesystem->exists($gitignorePath) || is_link($gitignorePath)) {
            if (!PathSecurity::isValidFile($gitignorePath)) {
                $io->writeError($manualHint);

                return;
            }
            $read = @file_get_contents($gitignorePath);
            if (false === $read) {
                $io->writeError($manualHint);

                return;
            }
            $contents = $read;
        }

        if (self::gitignoreMentionsConfig($contents)) {
            return;
        }

        $separator = ('' === $contents || str_ends_with($contents, "\n")) ? '' : "\n";
        try {
            $filesystem->appendToFile(
                $gitignorePath,
                $separator."# drupal/ai_best_practices: per-developer settings, see its README\n".self::CONFIG_GITIGNORE_ENTRY."\n",
            );
            $io->write('<info>AI Best Practices: added '.self::CONFIG_GITIGNORE_ENTRY.' to .gitignore</info>');
        } catch (\Exception $e) {
            $io->writeError('<warning>AI Best Practices: could not update .gitignore: '.$e->getMessage().'</warning>');
            $io->writeError($manualHint);
        }
    }

    /**
     * Whether a .gitignore already has a rule for the root configuration file.
     */
    private static function gitignoreMentionsConfig(string $contents): bool
    {
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $pattern = rtrim($line);
            if ('' === $pattern || str_starts_with($pattern, '#')) {
                continue;
            }
            if ('ai_best_practices.yaml' === ltrim($pattern, '!/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sets skills.output_directory in the ai_best_practices.yaml.dist contents.
     */
    private static function withOutputDirectory(string $template, string $outputDirectory): string
    {
        return preg_replace('/^(\s*output_directory:).*$/m', '$1 '.$outputDirectory, $template, 1) ?? $template;
    }

    /**
     * Runs skills-sync via the console application, then AGENTS.md + CLAUDE/GEMINI sync in-process.
     */
    public static function runSyncCli(IOInterface $io): int
    {
        $stderr = fopen('php://stderr', 'w');
        $output = new StreamOutput(false !== $stderr ? $stderr : STDOUT);

        $app = new AiBestPracticesApplication();
        $app->setAutoExit(false);
        $skillsExit = (int) $app->run(new ArrayInput(['command' => 'drupal-ai:skills-sync']), $output);
        if (0 !== $skillsExit) {
            return $skillsExit;
        }

        try {
            $projectRoot = ProjectRootResolver::resolve();
        } catch (\RuntimeException) {
            $io->writeError('<error>drupal/ai_best_practices: could not resolve project root for AGENTS.md sync</error>');

            return 1;
        }

        return AgentsProjectSync::run($projectRoot, PackageRoot::path(), $io);
    }

    private static function skipSyncDueToEnv(): bool
    {
        return (bool) getenv('AI_BEST_PRACTICES_SKIP_PACKAGE_SYNC');
    }
}
