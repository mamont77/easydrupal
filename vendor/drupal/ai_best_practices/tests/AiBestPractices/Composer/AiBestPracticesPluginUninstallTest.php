<?php

declare(strict_types=1);

namespace Drupal\AiBestPractices\Tests\AiBestPractices\Composer;

use Composer\Composer;
use Composer\Config;
use Composer\Installer\InstallationManager;
use Composer\IO\BufferIO;
use Composer\Json\JsonFile;
use Composer\Package\Link;
use Composer\Package\Locker;
use Composer\Package\RootPackage;
use Composer\Semver\Constraint\MatchAllConstraint;
use Drupal\AiBestPractices\Composer\AiBestPracticesPlugin;
use Drupal\AiBestPractices\Composer\ComposerProjectSynchronizer;
use Drupal\AiBestPractices\Console\AiBestPracticesApplication;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Composer calls PluginInterface::uninstall() for real removals and for reinstalls.
 */
final class AiBestPracticesPluginUninstallTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectRoot = sys_get_temp_dir().'/abp-plugin-uninstall-'.uniqid('', true);
        mkdir($this->projectRoot.'/vendor', 0755, true);
        file_put_contents($this->projectRoot.'/ai_best_practices.yaml', "auto_register_composer_scripts: true\n");
        $this->writeJson($this->projectRoot.'/composer.json', [
            'name' => 'test/consumer',
            'require-dev' => [ComposerProjectSynchronizer::PACKAGE_NAME => '^1.0'],
            'scripts' => [
                AiBestPracticesApplication::COMPOSER_SCRIPT_NAME => AiBestPracticesApplication::COMPOSER_SCRIPT_CALLBACK,
            ],
            'config' => [
                'allow-plugins' => [ComposerProjectSynchronizer::PACKAGE_NAME => true],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectRoot);
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function lockSectionProvider(): iterable
    {
        yield 'require-dev (composer reinstall, composer install --no-dev)' => ['packages-dev'];
        yield 'require (composer reinstall)' => ['packages'];
    }

    #[DataProvider('lockSectionProvider')]
    public function testKeepsProjectFilesWhenLockStillListsPackage(string $lockSection): void
    {
        $this->writeLock([$lockSection => [['name' => ComposerProjectSynchronizer::PACKAGE_NAME, 'version' => '1.0.0']]]);
        $composerJsonBefore = (string) file_get_contents($this->projectRoot.'/composer.json');

        (new AiBestPracticesPlugin())->uninstall($this->createComposer(false), new BufferIO());

        $this->assertFileExists($this->projectRoot.'/ai_best_practices.yaml');
        $this->assertSame($composerJsonBefore, file_get_contents($this->projectRoot.'/composer.json'));
    }

    public function testCleansUpWhenLockNoLongerListsPackage(): void
    {
        // `composer remove` writes the lock before it runs the uninstall operation.
        $this->writeLock(['packages' => [['name' => 'psr/log', 'version' => '3.0.0']]]);

        (new AiBestPracticesPlugin())->uninstall($this->createComposer(false), new BufferIO());

        $this->assertCleanedUp();
    }

    public function testKeepsProjectFilesWithoutLockWhenRootStillRequiresPackage(): void
    {
        $composerJsonBefore = (string) file_get_contents($this->projectRoot.'/composer.json');

        (new AiBestPracticesPlugin())->uninstall($this->createComposer(true), new BufferIO());

        $this->assertFileExists($this->projectRoot.'/ai_best_practices.yaml');
        $this->assertSame($composerJsonBefore, file_get_contents($this->projectRoot.'/composer.json'));
    }

    public function testCleansUpWithoutLockWhenRootNoLongerRequiresPackage(): void
    {
        (new AiBestPracticesPlugin())->uninstall($this->createComposer(false), new BufferIO());

        $this->assertCleanedUp();
    }

    private function assertCleanedUp(): void
    {
        $this->assertFileDoesNotExist($this->projectRoot.'/ai_best_practices.yaml');
        $data = (new JsonFile($this->projectRoot.'/composer.json'))->read();
        $this->assertIsArray($data);
        $this->assertArrayNotHasKey('scripts', $data);
        $this->assertArrayNotHasKey('config', $data);
    }

    /**
     * @param array<string, list<array<string, string>>> $sections
     */
    private function writeLock(array $sections): void
    {
        $this->writeJson($this->projectRoot.'/composer.lock', $sections + ['packages' => [], 'packages-dev' => []]);
    }

    /**
     * Creates a fixture file with plain file_put_contents().
     *
     * Composer's JsonFile::write() stat()s the target before it exists and silences
     * the resulting warning via error_reporting(); PHPUnit's error handler reports
     * such silenced warnings anyway, and failOnWarning turns them into a failed run.
     *
     * @param array<string, mixed> $data
     */
    private function writeJson(string $path, array $data): void
    {
        file_put_contents($path, json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n");
    }

    private function createComposer(bool $rootRequiresPackage): Composer
    {
        $config = new Config(false, $this->projectRoot);
        $config->merge(['config' => ['vendor-dir' => $this->projectRoot.'/vendor']]);

        $rootPackage = new RootPackage('test/consumer', '1.0.0.0', '1.0.0');
        if ($rootRequiresPackage) {
            $rootPackage->setDevRequires([
                ComposerProjectSynchronizer::PACKAGE_NAME => new Link(
                    'test/consumer',
                    ComposerProjectSynchronizer::PACKAGE_NAME,
                    new MatchAllConstraint(),
                    Link::TYPE_DEV_REQUIRE,
                ),
            ]);
        }

        $composer = new Composer();
        $composer->setConfig($config);
        $composer->setPackage($rootPackage);
        $composer->setLocker(new Locker(
            new BufferIO(),
            new JsonFile($this->projectRoot.'/composer.lock'),
            $this->createMock(InstallationManager::class),
            '{}',
        ));

        return $composer;
    }
}
