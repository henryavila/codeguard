<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Analyze;

use Illuminate\Filesystem\Filesystem;

/**
 * Accepted-finding suppression for iterative review.
 *
 * Reverses the v0 "AI findings are never baselined" rule (a deliberate decision
 * — see docs/specs/2026-06-03-patterns-engine-design.md): policing a contractor
 * is iterative, and a reviewer with zero memory re-flags the same accepted smell
 * forever and gets disabled. Acceptance is explicit (`--accept`) and never
 * silent — suppressed findings always surface as a visible count.
 *
 * Fingerprint = sha1(pattern_key + relative_file). Deliberately excludes the
 * free-text message (the LLM re-phrases it run-to-run, which would defeat the
 * baseline) and the line number (edits above a finding would shift it). Trade-off:
 * acceptance is per-pattern-per-file, not per-occurrence — documented, not a bug.
 */
final class AnalyzeBaseline
{
    /** @var array<string, bool>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly string $path,
        private readonly string $workingDirectory,
    ) {}

    public function isAccepted(PatternMatch $match): bool
    {
        return isset($this->fingerprints()[$this->fingerprint($match)]);
    }

    /**
     * Add the given findings' fingerprints to the baseline.
     *
     * @param  list<PatternMatch>  $matches
     * @return int number of newly-accepted findings
     */
    public function accept(array $matches): int
    {
        $fingerprints = $this->fingerprints();
        $added = 0;

        foreach ($matches as $match) {
            $fingerprint = $this->fingerprint($match);
            if (! isset($fingerprints[$fingerprint])) {
                $fingerprints[$fingerprint] = true;
                $added++;
            }
        }

        $keys = array_keys($fingerprints);
        sort($keys);

        $this->filesystem->ensureDirectoryExists(dirname($this->path));
        $this->filesystem->put(
            $this->path,
            (json_encode(['fingerprints' => $keys], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}')."\n",
        );

        $this->cache = $fingerprints;

        return $added;
    }

    public function fingerprint(PatternMatch $match): string
    {
        return sha1($match->patternKey.'|'.$this->relative($match->file));
    }

    /**
     * @return array<string, bool>
     */
    private function fingerprints(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        if (! $this->filesystem->exists($this->path)) {
            return $this->cache = [];
        }

        $decoded = json_decode($this->filesystem->get($this->path), true);
        $list = (is_array($decoded) && is_array($decoded['fingerprints'] ?? null)) ? $decoded['fingerprints'] : [];

        $set = [];
        foreach ($list as $fingerprint) {
            if (is_string($fingerprint)) {
                $set[$fingerprint] = true;
            }
        }

        return $this->cache = $set;
    }

    private function relative(string $absolute): string
    {
        $prefix = rtrim($this->workingDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $relative = str_starts_with($absolute, $prefix)
            ? substr($absolute, strlen($prefix))
            : $absolute;

        return str_replace('\\', '/', $relative);
    }
}
