<?php

namespace Drupal\AiBestPractices\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * End-to-end proof that Composer lifecycle works with this plugin enabled.
 *
 * Builds a disposable copy of this package so the scenario matches a real consumer:
 * path repo → installing this package schedules sync → post-install-dump runs
 * `skills-sync` then AGENTS.md / redirect sync, and registers the `drupal-ai` script.
 * Unrelated `composer remove` does not re-run that sync.
 *
 * Requires: composer on PATH, network for Packagist on first run.
 *
 * @group integration
 */
final class ComposerRemoveWithAiBestPracticesTest extends TestCase
{
    private string $workspace;

    private string $disposablePackageDir;

    private string $consumerDir;

    protected function setUp(): void
    {
        parent::setUp();
        if (!$this->composerIsAvailable()) {
            $this->markTestSkipped('composer executable not found on PATH (set COMPOSER_BIN if needed).');
        }
        $this->workspace = sys_get_temp_dir().'/abp-composer-remove-'.uniqid('', true);
        $this->disposablePackageDir = $this->workspace.'/ai_best_practices_pkg';
        $this->consumerDir = $this->workspace.'/consumer';
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workspace);
        parent::tearDown();
    }

    public function testConsumerInstallSyncsSkillsAndAgentsAndRemoveDoesNotResync(): void
    {
        $sourceRoot = dirname(__DIR__, 2);
        $this->buildDisposablePackage($sourceRoot, $this->disposablePackageDir);

        putenv('COMPOSER_HOME='.$this->workspace.'/.composer-home');
        $r1 = $this->runComposer($this->disposablePackageDir, ['install', '--no-dev']);
        $this->assertSame(0, $r1['exit'], $this->formatComposerFailure('package:install', $r1));

        mkdir($this->consumerDir, 0755, true);
        $this->writeConsumerComposerJson($this->disposablePackageDir);

        $r2 = $this->runComposer($this->consumerDir, ['update']);
        $this->assertSame(0, $r2['exit'], $this->formatComposerFailure('consumer:update', $r2));

        $merged2 = $r2['stdout'].$r2['stderr'];
        $this->assertStringContainsString('Syncing Agent Skills', $merged2);
        $this->assertStringContainsString('drupal/ai_best_practices: updated AGENTS.md', $merged2);

        $consumerJson = json_decode((string) file_get_contents($this->consumerDir.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('scripts', $consumerJson);
        $this->assertArrayHasKey('drupal-ai', $consumerJson['scripts']);
        $this->assertStringContainsString('AiBestPracticesApplication::runFromComposer', (string) $consumerJson['scripts']['drupal-ai']);

        $postUpdate = $this->normalizeScriptList($consumerJson['scripts']['post-update-cmd'] ?? []);
        $this->assertNotContains('@composer drupal-ai skills-sync', $postUpdate);
        $this->assertNotContains('@composer drupal-ai agents-generate', $postUpdate);

        $this->assertFileExists($this->consumerDir.'/AGENTS.md');

        $r3 = $this->runComposer($this->consumerDir, ['require', 'psr/log:^3']);
        $this->assertSame(0, $r3['exit'], $this->formatComposerFailure('consumer:require', $r3));

        $r4 = $this->runComposer($this->consumerDir, ['remove', 'psr/log']);
        $this->assertSame(0, $r4['exit'], $this->formatComposerFailure('consumer:remove', $r4));
        $merged4 = $r4['stdout'].$r4['stderr'];
        $this->assertStringNotContainsString('Syncing Agent Skills', $merged4);
    }

    public function testConsumerRemoveDrupalAiBestPracticesCleansComposerYamlAndLeavesAgents(): void
    {
        $sourceRoot = dirname(__DIR__, 2);
        $this->buildDisposablePackage($sourceRoot, $this->disposablePackageDir);

        putenv('COMPOSER_HOME='.$this->workspace.'/.composer-home');
        $r1 = $this->runComposer($this->disposablePackageDir, ['install', '--no-dev']);
        $this->assertSame(0, $r1['exit'], $this->formatComposerFailure('package:install', $r1));

        mkdir($this->consumerDir, 0755, true);
        $this->writeConsumerComposerJson($this->disposablePackageDir);

        $r2 = $this->runComposer($this->consumerDir, ['update']);
        $this->assertSame(0, $r2['exit'], $this->formatComposerFailure('consumer:update', $r2));

        $this->assertFileExists($this->consumerDir.'/ai_best_practices.yaml');
        $this->assertFileExists($this->consumerDir.'/AGENTS.md');
        $this->assertDirectoryExists($this->consumerDir.'/vendor/drupal/ai_best_practices');
        $this->assertFileExists($this->consumerDir.'/.gitignore');
        $this->assertContains('/ai_best_practices.yaml', file($this->consumerDir.'/.gitignore', FILE_IGNORE_NEW_LINES) ?: []);

        $r3 = $this->runComposer($this->consumerDir, ['remove', 'drupal/ai_best_practices']);
        $this->assertSame(0, $r3['exit'], $this->formatComposerFailure('consumer:remove:drupal/ai_best_practices', $r3));

        $this->assertDirectoryDoesNotExist($this->consumerDir.'/vendor/drupal/ai_best_practices');
        $this->assertFileDoesNotExist($this->consumerDir.'/ai_best_practices.yaml');
        $this->assertFileDoesNotExist($this->consumerDir.'/vendor/.ai_bp_sync_pending');
        $this->assertFileExists($this->consumerDir.'/AGENTS.md');

        $consumerJson = json_decode((string) file_get_contents($this->consumerDir.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('scripts', $consumerJson);
        $this->assertArrayNotHasKey('config', $consumerJson);
        $this->assertArrayNotHasKey('drupal/ai_best_practices', $consumerJson['require'] ?? []);
    }

    private function composerIsAvailable(): bool
    {
        $bin = getenv('COMPOSER_BIN') ?: 'composer';
        $r = $this->runComposerPassthrough(dirname(__DIR__, 2), [$bin, '--version']);

        return 0 === $r['exit'];
    }

    /**
     * @param list<string> $args
     *                           Arguments after "composer"
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runComposer(string $cwd, array $args): array
    {
        $bin = getenv('COMPOSER_BIN') ?: 'composer';
        $cmd = $bin.' -n --no-interaction '.implode(' ', array_map(static function (string $a): string {
            return escapeshellarg($a);
        }, $args));

        return $this->runShell($cwd, $cmd);
    }

    /**
     * @param list<string> $command
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runComposerPassthrough(string $cwd, array $command): array
    {
        $cmd = implode(' ', array_map(static function (string $a): string {
            return escapeshellarg($a);
        }, $command));

        return $this->runShell($cwd, $cmd);
    }

    /**
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runShell(string $cwd, string $command): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptorSpec, $pipes, $cwd);
        if (false === $process) {
            return ['exit' => 1, 'stdout' => '', 'stderr' => 'proc_open failed'];
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return [
            'exit' => $exit,
            'stdout' => false !== $stdout ? $stdout : '',
            'stderr' => false !== $stderr ? $stderr : '',
        ];
    }

    /**
     * @param array{exit: int, stdout: string, stderr: string} $r
     */
    private function formatComposerFailure(string $step, array $r): string
    {
        return sprintf(
            "%s failed (exit %d).\nSTDOUT:\n%s\nSTDERR:\n%s\n",
            $step,
            $r['exit'],
            $r['stdout'],
            $r['stderr'],
        );
    }

    private function buildDisposablePackage(string $sourceRoot, string $targetRoot): void
    {
        mkdir($targetRoot, 0755, true);
        $filesystem = new Filesystem();
        $filesystem->mirror($sourceRoot.'/src', $targetRoot.'/src');
        // Packaged markdown lives under .agents/ (AGENTS.md.template, CLAUDE/GEMINI, SETUP).
        if (is_dir($sourceRoot.'/.agents')) {
            $filesystem->mirror($sourceRoot.'/.agents', $targetRoot.'/.agents');
        }
        $filesystem->copy($sourceRoot.'/ai_best_practices.yaml.dist', $targetRoot.'/ai_best_practices.yaml.dist', true);
        if (is_dir($sourceRoot.'/skills')) {
            $filesystem->mirror($sourceRoot.'/skills', $targetRoot.'/skills');
        }
        $composer = json_decode((string) file_get_contents($sourceRoot.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        unset($composer['require-dev'], $composer['autoload-dev']);
        file_put_contents(
            $targetRoot.'/composer.json',
            json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
        );
    }

    private function writeConsumerComposerJson(string $packagePath): void
    {
        $url = str_replace('\\', '/', (string) realpath($packagePath));
        $this->assertNotSame('', $url);
        $data = [
            'name' => 'test/abp-consumer',
            'type' => 'project',
            'license' => 'MIT',
            'repositories' => [
                [
                    'type' => 'path',
                    'url' => $url,
                    'options' => ['symlink' => false],
                ],
            ],
            'require' => [
                'drupal/ai_best_practices' => '*',
            ],
            'config' => [
                'allow-plugins' => [
                    'drupal/ai_best_practices' => true,
                ],
            ],
            'minimum-stability' => 'dev',
            'prefer-stable' => true,
        ];
        file_put_contents(
            $this->consumerDir.'/composer.json',
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
        );
    }

    /**
     * @return list<string>
     */
    private function normalizeScriptList(mixed $scripts): array
    {
        if (\is_string($scripts)) {
            return [$scripts];
        }
        if (\is_array($scripts)) {
            $out = [];
            foreach ($scripts as $item) {
                if (\is_string($item)) {
                    $out[] = $item;
                }
            }

            return $out;
        }

        return [];
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $p = $item->getPathname();
            if ($item->isDir()) {
                @rmdir($p);
            } else {
                @unlink($p);
            }
        }
        @rmdir($path);
    }
}
