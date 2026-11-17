<?php

declare(strict_types=1);

namespace Drupal\AiBestPractices\Tests\Agents;

use Drupal\AiBestPractices\Agents\AgentRedirectFiles;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Covers CLAUDE.md / GEMINI.md sync at project root (temp dirs only — never touches repo root).
 */
final class AgentRedirectFilesTest extends TestCase
{
    private Filesystem $filesystem;

    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->filesystem = new Filesystem();
        $this->workspace = sys_get_temp_dir().'/ai_bp_redirect_'.bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->workspace);
    }

    protected function tearDown(): void
    {
        if (isset($this->workspace) && is_dir($this->workspace)) {
            $this->filesystem->remove($this->workspace);
        }
        parent::tearDown();
    }

    public function testCopiesRedirectsWhenMissingUsesDotAgentsSources(): void
    {
        $project = $this->workspace.'/site';
        $package = $this->workspace.'/vendor-pkg';
        $this->filesystem->mkdir($project);
        $this->filesystem->mkdir($package.'/.agents');
        $this->filesystem->copy($this->repoAgentsPath('CLAUDE.md'), $package.'/.agents/CLAUDE.md');
        $this->filesystem->copy($this->repoAgentsPath('GEMINI.md'), $package.'/.agents/GEMINI.md');

        $expectedClaude = $this->normalizedFileContents($package.'/.agents/CLAUDE.md');
        $expectedGemini = $this->normalizedFileContents($package.'/.agents/GEMINI.md');

        AgentRedirectFiles::sync($project, $package, $this->filesystem, null);

        self::assertSame($expectedClaude, $this->normalizedFileContents($project.'/CLAUDE.md'));
        self::assertSame($expectedGemini, $this->normalizedFileContents($project.'/GEMINI.md'));
    }

    public function testPreservesExistingRedirectsWithoutOverwrite(): void
    {
        $project = $this->workspace.'/site2';
        $package = $this->workspace.'/vendor-pkg2';
        $this->filesystem->mkdir($project);
        $this->filesystem->mkdir($package.'/.agents');
        $this->filesystem->copy($this->repoAgentsPath('CLAUDE.md'), $package.'/.agents/CLAUDE.md');
        $this->filesystem->copy($this->repoAgentsPath('GEMINI.md'), $package.'/.agents/GEMINI.md');
        $this->filesystem->dumpFile($project.'/CLAUDE.md', "custom claude\n");
        $this->filesystem->dumpFile($project.'/GEMINI.md', "custom gemini\n");

        AgentRedirectFiles::sync($project, $package, $this->filesystem, null);

        self::assertSame("custom claude\n", file_get_contents($project.'/CLAUDE.md'));
        self::assertSame("custom gemini\n", file_get_contents($project.'/GEMINI.md'));
    }

    public function testThrowsWhenPackagedAliasesMissing(): void
    {
        $project = $this->workspace.'/site3';
        $package = $this->workspace.'/vendor-min';
        $this->filesystem->mkdir($project);
        $this->filesystem->mkdir($package);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing package source at');

        AgentRedirectFiles::sync($project, $package, $this->filesystem, null);
    }

    /**
     * Repository `.agents/` sources (same tree the package ships for reference).
     */
    private function repoAgentsPath(string $filename): string
    {
        return dirname(__DIR__, 2).'/.agents/'.$filename;
    }

    private function normalizedFileContents(string $path): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents($path));
    }
}
