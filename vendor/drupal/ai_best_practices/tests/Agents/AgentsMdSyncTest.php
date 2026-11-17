<?php

declare(strict_types=1);

namespace Drupal\AiBestPractices\Tests\Agents;

use Drupal\AiBestPractices\Agents\AgentsMdSync;
use Drupal\AiBestPractices\Agents\MalformedAgentsMdException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class AgentsMdSyncTest extends TestCase
{
    private Filesystem $filesystem;

    private AgentsMdSync $sync;

    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->filesystem = new Filesystem();
        $this->sync = new AgentsMdSync($this->filesystem);
        $this->workspace = sys_get_temp_dir().'/ai_bp_test_'.bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->workspace);
    }

    protected function tearDown(): void
    {
        if (isset($this->workspace) && is_dir($this->workspace)) {
            $this->filesystem->remove($this->workspace);
        }
        parent::tearDown();
    }

    public function testReadPayloadExtractsInnerFromTemplate(): void
    {
        $package = $this->workspace.'/pkg';
        $inner = "line1\nline2";
        $this->writePackageTemplate($package, $inner);

        self::assertSame($inner, $this->sync->readPayload($package));
    }

    public function testSyncCreatesAgentsWhenMissing(): void
    {
        $project = $this->workspace.'/proj';
        $package = $this->workspace.'/pkg2';
        $this->filesystem->mkdir($project);
        $full = $this->writePackageTemplate($package, "payload\nhere");

        $written = $this->sync->sync($project, $package);

        self::assertTrue($written);
        self::assertSame($full, file_get_contents($project.'/AGENTS.md'));
    }

    public function testSyncReplacesInnerWhenMarkersPresent(): void
    {
        $project = $this->workspace.'/proj3';
        $package = $this->workspace.'/pkg3';
        $this->filesystem->mkdir($project);
        $this->writePackageTemplate($package, 'NEW_INNER');

        $consumer = "# intro\n\n".AgentsMdSync::MARKER_START."\nOLD_INNER\n".AgentsMdSync::MARKER_END."\nfooter\n";
        $this->filesystem->dumpFile($project.'/AGENTS.md', $consumer);

        $written = $this->sync->sync($project, $package);

        self::assertTrue($written);
        $out = (string) file_get_contents($project.'/AGENTS.md');
        self::assertStringContainsString('NEW_INNER', $out);
        self::assertStringNotContainsString('OLD_INNER', $out);
        self::assertStringContainsString('# intro', $out);
        self::assertStringContainsString('footer', $out);
    }

    public function testSyncReturnsFalseWhenInnerAlreadyMatches(): void
    {
        $project = $this->workspace.'/proj4';
        $package = $this->workspace.'/pkg4';
        $this->filesystem->mkdir($project);
        $inner = "same\nbody";
        $this->writePackageTemplate($package, $inner);
        $consumer = AgentsMdSync::MARKER_START."\n".$inner."\n".AgentsMdSync::MARKER_END;
        $this->filesystem->dumpFile($project.'/AGENTS.md', $consumer);

        $written = $this->sync->sync($project, $package);

        self::assertFalse($written);
        self::assertSame($consumer, file_get_contents($project.'/AGENTS.md'));
    }

    public function testSyncPrependsManagedBlockWhenNoMarkers(): void
    {
        $project = $this->workspace.'/proj5';
        $package = $this->workspace.'/pkg5';
        $this->filesystem->mkdir($project);
        $this->writePackageTemplate($package, 'INDEX');
        $this->filesystem->dumpFile($project.'/AGENTS.md', "# My notes\n\nKeep this.");

        $written = $this->sync->sync($project, $package);

        self::assertTrue($written);
        $out = (string) file_get_contents($project.'/AGENTS.md');
        self::assertStringStartsWith(AgentsMdSync::MARKER_START, $out);
        self::assertStringContainsString('INDEX', $out);
        self::assertStringContainsString('# My notes', $out);
        self::assertLessThan(strpos($out, '# My notes'), strpos($out, AgentsMdSync::MARKER_END));
    }

    public function testSyncThrowsWhenConsumerHasDuplicateStartMarkers(): void
    {
        $project = $this->workspace.'/proj6';
        $package = $this->workspace.'/pkg6';
        $this->filesystem->mkdir($project);
        $this->writePackageTemplate($package, 'P');
        $bad = AgentsMdSync::MARKER_START."\na\n".AgentsMdSync::MARKER_START."\nb\n".AgentsMdSync::MARKER_END;
        $this->filesystem->dumpFile($project.'/AGENTS.md', $bad);

        $this->expectException(MalformedAgentsMdException::class);
        $this->sync->sync($project, $package);
    }

    private function writePackageTemplate(string $packageRoot, string $innerBody): string
    {
        $this->filesystem->mkdir($packageRoot.'/.agents');
        $full = "# Title\n\n".AgentsMdSync::MARKER_START."\n".$innerBody."\n".AgentsMdSync::MARKER_END."\n";
        $this->filesystem->dumpFile($packageRoot.'/.agents/AGENTS.md.template', $full);

        return $full;
    }
}
