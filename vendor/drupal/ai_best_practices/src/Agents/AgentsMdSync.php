<?php

declare(strict_types=1);

namespace Drupal\AiBestPractices\Agents;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

/**
 * Syncs a consumer Drupal project's root AGENTS.md using one managed HTML-comment region.
 *
 * The canonical consumer default lives in `.agents/AGENTS.md.template` (full file,
 * including outer copy and markers). The plugin extracts the inner body from that
 * file to refresh the consumer's managed region only.
 */
final class AgentsMdSync
{
    public const MARKER_START = '<!-- ai-best-practices:start -->';

    public const MARKER_END = '<!-- ai-best-practices:end -->';

    private const CONSUMER_TEMPLATE = '.agents/AGENTS.md.template';

    public function __construct(
        private readonly Filesystem $filesystem,
    ) {
    }

    /**
     * @return bool true if AGENTS.md was written, false if skipped (already up to date)
     *
     * @throws MalformedAgentsMdException
     */
    public function sync(string $projectRoot, string $packageRoot): bool
    {
        $projectRoot = Path::canonicalize($projectRoot);
        $packageRoot = Path::canonicalize($packageRoot);

        $agentsPath = $projectRoot.'/AGENTS.md';
        $templatePath = $packageRoot.'/'.self::CONSUMER_TEMPLATE;
        $canonical = $this->readFullConsumerTemplate($templatePath);
        $payload = $this->extractInnerFromCanonical($canonical, $templatePath);

        if (!$this->filesystem->exists($agentsPath)) {
            $this->filesystem->dumpFile($agentsPath, $canonical);

            return true;
        }

        $original = $this->normalizeNewlines((string) file_get_contents($agentsPath));
        $result = $this->mergeExisting($original, $payload);
        if (null === $result) {
            return false;
        }
        $this->filesystem->dumpFile($agentsPath, $result);

        return true;
    }

    /**
     * Inner body (between markers) from the package consumer template, for tests.
     *
     * @throws \RuntimeException
     */
    public function readPayload(string $packageRoot): string
    {
        $packageRoot = Path::canonicalize($packageRoot);
        $templatePath = $packageRoot.'/'.self::CONSUMER_TEMPLATE;
        $canonical = $this->readFullConsumerTemplate($templatePath);

        return $this->extractInnerFromCanonical($canonical, $templatePath);
    }

    private function readFullConsumerTemplate(string $templatePath): string
    {
        if (!$this->filesystem->exists($templatePath)) {
            throw new \RuntimeException('Missing consumer template at '.$templatePath);
        }

        return $this->normalizeNewlines((string) file_get_contents($templatePath));
    }

    /**
     * @throws \RuntimeException
     */
    private function extractInnerFromCanonical(string $canonical, string $templatePath): string
    {
        $startCount = substr_count($canonical, self::MARKER_START);
        $endCount = substr_count($canonical, self::MARKER_END);
        if (1 !== $startCount || 1 !== $endCount) {
            throw new \RuntimeException(sprintf('%s must contain exactly one %s and one %s (found %d start, %d end).', $templatePath, self::MARKER_START, self::MARKER_END, $startCount, $endCount));
        }
        $startPos = strpos($canonical, self::MARKER_START);
        $endPos = strpos($canonical, self::MARKER_END);
        assert(false !== $startPos && false !== $endPos);
        if ($endPos <= $startPos) {
            throw new \RuntimeException($templatePath.': '.self::MARKER_END.' must appear after '.self::MARKER_START.'.');
        }
        $innerStart = $startPos + \strlen(self::MARKER_START);
        $inner = substr($canonical, $innerStart, $endPos - $innerStart);

        return trim($inner, "\r\n");
    }

    /**
     * @return null if file unchanged (idempotent skip)
     *
     * @throws MalformedAgentsMdException
     */
    private function mergeExisting(string $content, string $payload): ?string
    {
        $startCount = substr_count($content, self::MARKER_START);
        $endCount = substr_count($content, self::MARKER_END);

        if (0 === $startCount && 0 === $endCount) {
            return $this->prependManagedBlock($content, $payload);
        }

        if (1 !== $startCount || 1 !== $endCount) {
            throw new MalformedAgentsMdException(sprintf('AGENTS.md must contain exactly one %s and one %s (found %d start, %d end).', self::MARKER_START, self::MARKER_END, $startCount, $endCount));
        }

        $startPos = strpos($content, self::MARKER_START);
        $endPos = strpos($content, self::MARKER_END);
        assert(false !== $startPos && false !== $endPos);
        if ($endPos <= $startPos) {
            throw new MalformedAgentsMdException(self::MARKER_END.' must appear after '.self::MARKER_START.'.');
        }

        $innerStart = $startPos + \strlen(self::MARKER_START);
        $inner = substr($content, $innerStart, $endPos - $innerStart);
        $innerTrimmed = trim($inner, "\r\n");

        if ($innerTrimmed === trim($payload, "\r\n")) {
            return null;
        }

        $prefix = substr($content, 0, $innerStart);
        $suffix = substr($content, $endPos);

        return $prefix."\n".$payload."\n".$suffix;
    }

    /**
     * Policy A: prepend managed block; preserve existing body.
     */
    private function prependManagedBlock(string $existing, string $payload): string
    {
        $block = self::MARKER_START."\n".$payload."\n".self::MARKER_END;
        $existing = preg_replace('/^\R+/', '', $existing) ?? $existing;

        return $block."\n\n".$existing;
    }

    private function normalizeNewlines(string $s): string
    {
        return str_replace("\r\n", "\n", $s);
    }
}
