<?php

declare(strict_types=1);

namespace Drupal\AiBestPractices\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Drupal\AiBestPractices\Config\AiBestPracticesConfigLoader;
use Drupal\AiBestPractices\Console\AiBestPracticesApplication;
use Drupal\AiBestPractices\PackageRoot;
use Drupal\AiBestPractices\ProjectRootResolver;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

/**
 * Composer plugin to register AI Best Practices scripts in consuming projects.
 */
class AiBestPracticesPlugin implements PluginInterface, EventSubscriberInterface
{
    public function activate(Composer $composer, IOInterface $io): void
    {
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
        $projectRoot = $this->resolveProjectRootForUninstall($composer);
        if ('' === $projectRoot) {
            return;
        }

        if (PackageRoot::isPackageOwnTree($projectRoot)) {
            return;
        }

        if ($this->packageStaysInstalled($composer)) {
            $io->write('<info>AI Best Practices: package is still required; kept ai_best_practices.yaml and composer.json settings</info>', true, IOInterface::VERBOSE);

            return;
        }

        $filesystem = new Filesystem();
        ComposerProjectSynchronizer::removeSyncPendingMarker($projectRoot);

        $yamlPath = Path::join($projectRoot, 'ai_best_practices.yaml');
        if ($filesystem->exists($yamlPath)) {
            try {
                $filesystem->remove($yamlPath);
                $io->write('<info>AI Best Practices: removed ai_best_practices.yaml</info>');
            } catch (\Exception $e) {
                $io->writeError('<warning>AI Best Practices: could not remove ai_best_practices.yaml: '.$e->getMessage().'</warning>');
            }
        }

        $composerJsonPath = Path::join($projectRoot, 'composer.json');
        if (!$filesystem->exists($composerJsonPath)) {
            return;
        }

        try {
            $jsonFile = new JsonFile($composerJsonPath);
            $jsonContent = $jsonFile->read();
            if (!\is_array($jsonContent)) {
                return;
            }
            $updated = ComposerConsumerRollback::stripPluginFromComposerData($jsonContent);
            if ($updated !== $jsonContent) {
                $jsonFile->write($updated);
                $io->write('<info>AI Best Practices: removed Composer settings registered for this package</info>');
            }
        } catch (\Exception $e) {
            $io->writeError('<warning>AI Best Practices: could not update composer.json on uninstall: '.$e->getMessage().'</warning>');
        }
    }

    /**
     * Tells a real removal from an uninstall that leaves the package in the project.
     *
     * Composer also calls uninstall() for the first half of `composer reinstall` and
     * for dev packages dropped by `composer install --no-dev`. Composer writes the
     * lock (or keeps it in memory with `lock: false`) before it runs any package
     * operation, so the lock describes the project after the current command.
     */
    private function packageStaysInstalled(Composer $composer): bool
    {
        $packageName = ComposerProjectSynchronizer::PACKAGE_NAME;

        try {
            $locker = $composer->getLocker();
            if ($locker->isLocked()) {
                $lockData = $locker->getLockData();
                foreach (['packages', 'packages-dev'] as $section) {
                    foreach ($lockData[$section] ?? [] as $lockedPackage) {
                        if (\is_array($lockedPackage) && ($lockedPackage['name'] ?? null) === $packageName) {
                            return true;
                        }
                    }
                }

                return false;
            }
        } catch (\Throwable) {
            // No usable lock: fall back to the root package requirements.
        }

        $rootPackage = $composer->getPackage();

        return isset($rootPackage->getRequires()[$packageName])
            || isset($rootPackage->getDevRequires()[$packageName]);
    }

    /**
     * @return string absolute project root, or empty string when it cannot be resolved
     */
    private function resolveProjectRootForUninstall(Composer $composer): string
    {
        $vendorDir = $composer->getConfig()->get('vendor-dir');
        if (!\is_string($vendorDir) || '' === $vendorDir) {
            try {
                return ProjectRootResolver::resolve();
            } catch (\RuntimeException) {
                return '';
            }
        }

        $filesystem = new Filesystem();
        if (!$filesystem->isAbsolutePath($vendorDir)) {
            try {
                $vendorDir = Path::join(ProjectRootResolver::resolve(), $vendorDir);
            } catch (\RuntimeException) {
                return '';
            }
        }

        $vendorDir = Path::canonicalize($vendorDir);
        $parent = Path::canonicalize(dirname($vendorDir));

        return '' !== $parent && $parent !== $vendorDir ? $parent : '';
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PackageEvents::POST_PACKAGE_INSTALL => 'onPostPackageInstall',
            PackageEvents::POST_PACKAGE_UPDATE => 'onPostPackageUpdate',
            'post-install-cmd' => 'registerScripts',
            'post-update-cmd' => 'registerScripts',
        ];
    }

    /**
     * Delegates so plugin self-updates resolve current vendor classes.
     */
    public function onPostPackageInstall(PackageEvent $event): void
    {
        ComposerProjectSynchronizer::markSyncPendingFromPackageEvent($event);
    }

    /**
     * Delegates so plugin self-updates resolve current vendor classes.
     */
    public function onPostPackageUpdate(PackageEvent $event): void
    {
        ComposerProjectSynchronizer::markSyncPendingFromPackageEvent($event);
    }

    /**
     * Deferred sync after autoload dump + register `drupal-ai` script in composer.json when enabled.
     */
    public function registerScripts(Event $event): void
    {
        $io = $event->getIO();

        try {
            $projectRoot = ProjectRootResolver::resolve();
        } catch (\RuntimeException $e) {
            return;
        }

        ComposerProjectSynchronizer::runDeferredSyncIfPending($projectRoot, $io);

        if (getenv('AI_BEST_PRACTICES_SKIP_COMPOSER_MUTATION')) {
            return;
        }

        $composerJsonPath = Path::join($projectRoot, 'composer.json');
        $filesystem = new Filesystem();

        if (!$filesystem->exists($composerJsonPath)) {
            return;
        }

        if (PackageRoot::isPackageOwnTree($projectRoot)) {
            return;
        }

        $packageDir = PackageRoot::path();

        try {
            $jsonFile = new JsonFile($composerJsonPath);
            $jsonContent = $jsonFile->read();

            ComposerProjectSynchronizer::ensureDefaultConfig($projectRoot, $packageDir, $filesystem, $io);

            $config = AiBestPracticesConfigLoader::load($projectRoot);
            if (($config['auto_register_composer_scripts'] ?? true) === false) {
                return;
            }

            if (!isset($jsonContent['scripts'])) {
                $jsonContent['scripts'] = [];
            }

            $scripts = &$jsonContent['scripts'];
            $callback = AiBestPracticesApplication::COMPOSER_SCRIPT_CALLBACK;
            $scriptName = AiBestPracticesApplication::COMPOSER_SCRIPT_NAME;
            $changed = false;

            if (isset($scripts['abp']) && $scripts['abp'] === $callback) {
                unset($scripts['abp']);
                $changed = true;
            }

            if (!isset($scripts[$scriptName])) {
                $scripts[$scriptName] = $callback;
                $changed = true;
            }

            if ($changed) {
                if ([] === $scripts) {
                    unset($jsonContent['scripts']);
                }
                $jsonFile->write($jsonContent);
                $io->write("<info>AI Best Practices: registered `{$scriptName}` script in composer.json</info>");
                $io->write("<info>You can use: composer {$scriptName} skills-sync and composer {$scriptName} skills-list</info>");
            }
        } catch (\InvalidArgumentException $e) {
            $io->writeError('<warning>Could not auto-register AI Best Practices scripts: '.$e->getMessage().'</warning>');
            $io->writeError('<warning>Fix ai_best_practices.yaml, then re-run composer install or composer update.</warning>');
        } catch (\Exception $e) {
            $io->writeError('<warning>Could not auto-register AI Best Practices scripts: '.$e->getMessage().'</warning>');
            $io->writeError('<warning>Add the drupal-ai script manually, or set auto_register_composer_scripts: false in ai_best_practices.yaml.</warning>');
        }
    }
}
