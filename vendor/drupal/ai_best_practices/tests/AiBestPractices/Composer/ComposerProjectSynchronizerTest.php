<?php

declare(strict_types=1);

namespace Drupal\AiBestPractices\Tests\AiBestPractices\Composer;

use Composer\IO\BufferIO;
use Drupal\AiBestPractices\Composer\ComposerProjectSynchronizer;
use Drupal\AiBestPractices\Config\AiBestPracticesConfigLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Creating ai_best_practices.yaml picks the skills output directory and git-ignores the file.
 */
final class ComposerProjectSynchronizerTest extends TestCase
{
    private const CLAUDE_ENV_VARS = ['CLAUDECODE', 'CLAUDE_CODE'];

    private string $workspace;

    private string $projectRoot;

    private string $packageDir;

    private Filesystem $filesystem;

    /**
     * @var array<string, string|false>
     */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->filesystem = new Filesystem();
        $this->workspace = sys_get_temp_dir().'/abp-synchronizer-'.uniqid('', true);
        $this->projectRoot = $this->workspace.'/project';
        $this->packageDir = dirname(__DIR__, 3);
        $this->filesystem->mkdir($this->projectRoot);

        // The suite may itself run inside Claude Code, so start every test without its env vars.
        foreach (self::CLAUDE_ENV_VARS as $name) {
            $this->originalEnv[$name] = getenv($name);
            putenv($name);
        }
        AiBestPracticesConfigLoader::clearCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $name => $value) {
            putenv(false === $value ? $name : "$name=$value");
        }
        AiBestPracticesConfigLoader::clearCache();
        $this->filesystem->remove($this->workspace);
        parent::tearDown();
    }

    public function testCreatesGitignoreWhenMissing(): void
    {
        $io = $this->ensureDefaultConfig();

        $this->assertFileExists($this->projectRoot.'/ai_best_practices.yaml');
        $this->assertSame([ComposerProjectSynchronizer::CONFIG_GITIGNORE_ENTRY], $this->gitignorePatterns());
        $this->assertStringContainsString('added /ai_best_practices.yaml to .gitignore', $io->getOutput());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function existingGitignoreProvider(): iterable
    {
        yield 'trailing newline' => ["/vendor/\n/web/core/\n"];
        yield 'no trailing newline' => ["/vendor/\n/web/core/"];
        yield 'CRLF line endings' => ["/vendor/\r\n/web/core/\r\n"];
    }

    #[DataProvider('existingGitignoreProvider')]
    public function testAppendsToExistingGitignoreAndKeepsItsContent(string $existing): void
    {
        file_put_contents($this->projectRoot.'/.gitignore', $existing);

        $this->ensureDefaultConfig();

        $this->assertSame(['/vendor/', '/web/core/', '/ai_best_practices.yaml'], $this->gitignorePatterns());
        $this->assertStringStartsWith($existing, (string) file_get_contents($this->projectRoot.'/.gitignore'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function existingRuleProvider(): iterable
    {
        yield 'root-anchored' => ["/vendor/\n/ai_best_practices.yaml\n"];
        yield 'unanchored' => ["ai_best_practices.yaml\n"];
        yield 'trailing whitespace' => ["/ai_best_practices.yaml  \n"];
        yield 'negation keeps the project committing it' => ["!/ai_best_practices.yaml\n"];
    }

    #[DataProvider('existingRuleProvider')]
    public function testLeavesGitignoreAloneWhenItAlreadyHasARule(string $existing): void
    {
        file_put_contents($this->projectRoot.'/.gitignore', $existing);

        $this->ensureDefaultConfig();

        $this->assertFileExists($this->projectRoot.'/ai_best_practices.yaml');
        $this->assertSame($existing, file_get_contents($this->projectRoot.'/.gitignore'));
    }

    public function testCommentedOutEntryDoesNotCount(): void
    {
        file_put_contents($this->projectRoot.'/.gitignore', "# /ai_best_practices.yaml\n");

        $this->ensureDefaultConfig();

        $this->assertSame(['/ai_best_practices.yaml'], $this->gitignorePatterns());
    }

    public function testExistingConfigLeavesGitignoreUntouched(): void
    {
        file_put_contents($this->projectRoot.'/ai_best_practices.yaml', "skills: {}\n");
        file_put_contents($this->projectRoot.'/.gitignore', "/vendor/\n");

        $this->ensureDefaultConfig();

        $this->assertSame("skills: {}\n", file_get_contents($this->projectRoot.'/ai_best_practices.yaml'));
        $this->assertSame("/vendor/\n", file_get_contents($this->projectRoot.'/.gitignore'));
    }

    public function testSymlinkedGitignoreIsNotWritten(): void
    {
        $target = $this->workspace.'/shared.gitignore';
        file_put_contents($target, "/vendor/\n");
        if (!@symlink($target, $this->projectRoot.'/.gitignore')) {
            $this->markTestSkipped('symlinks are not available on this filesystem.');
        }

        $io = $this->ensureDefaultConfig();

        $this->assertFileExists($this->projectRoot.'/ai_best_practices.yaml');
        $this->assertSame("/vendor/\n", file_get_contents($target));
        $this->assertStringContainsString('add /ai_best_practices.yaml to .gitignore yourself', $io->getOutput());
    }

    public function testDefaultsToAgentsSkillsOutsideClaudeCode(): void
    {
        $this->ensureDefaultConfig();

        $this->assertConfiguredOutputDirectory('.agents/skills');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function claudeCodeEnvVarProvider(): iterable
    {
        yield 'CLAUDECODE' => ['CLAUDECODE'];
        yield 'CLAUDE_CODE' => ['CLAUDE_CODE'];
    }

    #[DataProvider('claudeCodeEnvVarProvider')]
    public function testDefaultsToClaudeSkillsWhenClaudeCodeEnvVarIsSet(string $envVar): void
    {
        putenv("$envVar=1");

        $io = $this->ensureDefaultConfig();

        $this->assertConfiguredOutputDirectory('.claude/skills');
        $this->assertStringContainsString('skills output: .claude/skills', $io->getOutput());
    }

    public function testDefaultsToClaudeSkillsWhenProjectHasClaudeDirectory(): void
    {
        $this->filesystem->mkdir($this->projectRoot.'/.claude');

        $this->ensureDefaultConfig();

        $this->assertConfiguredOutputDirectory('.claude/skills');
    }

    public function testExistingConfigIsNotChangedByDetection(): void
    {
        $existingConfig = "skills:\n  output_directory: custom/skills\n";
        $this->filesystem->dumpFile($this->projectRoot.'/ai_best_practices.yaml', $existingConfig);
        putenv('CLAUDECODE=1');

        $this->ensureDefaultConfig();

        $this->assertStringEqualsFile($this->projectRoot.'/ai_best_practices.yaml', $existingConfig);
    }

    public function testCreatedConfigKeepsTheRestOfTheTemplate(): void
    {
        putenv('CLAUDECODE=1');

        $this->ensureDefaultConfig();

        $template = (string) file_get_contents($this->packageDir.'/ai_best_practices.yaml.dist');
        $created = (string) file_get_contents($this->projectRoot.'/ai_best_practices.yaml');
        $this->assertSame(
            str_replace('output_directory: .agents/skills', 'output_directory: .claude/skills', $template),
            $created,
        );
    }

    private function ensureDefaultConfig(): BufferIO
    {
        $io = new BufferIO();
        ComposerProjectSynchronizer::ensureDefaultConfig($this->projectRoot, $this->packageDir, $this->filesystem, $io);

        return $io;
    }

    private function assertConfiguredOutputDirectory(string $expected): void
    {
        $config = AiBestPracticesConfigLoader::load($this->projectRoot);
        $this->assertSame($expected, $config['skills']['output_directory']);
    }

    /**
     * @return list<string>
     */
    private function gitignorePatterns(): array
    {
        $lines = preg_split('/\R/', (string) file_get_contents($this->projectRoot.'/.gitignore')) ?: [];

        return array_values(array_filter(
            array_map('trim', $lines),
            static fn (string $line): bool => '' !== $line && !str_starts_with($line, '#'),
        ));
    }
}
