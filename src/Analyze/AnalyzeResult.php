<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Analyze;

/**
 * Outcome of an analyze run.
 *
 * `adjudicated` is false when no real LLM driver was configured — the command
 * uses it to print an honest degradation notice instead of treating an empty
 * result as a clean repo.
 */
final readonly class AnalyzeResult
{
    /**
     * @param  list<PatternMatch>  $matches
     */
    public function __construct(
        public int $patternsChecked,
        public array $matches,
        public int $durationMs,
        public bool $adjudicated = true,
    ) {}

    /**
     * Passes when no finding reaches the $failOn threshold. A null threshold
     * (`--fail-on=never`) always passes.
     */
    public function passed(?Severity $failOn): bool
    {
        if ($failOn === null) {
            return true;
        }

        foreach ($this->matches as $match) {
            if ($match->severity->meets($failOn)) {
                return false;
            }
        }

        return true;
    }

    public function failed(?Severity $failOn): bool
    {
        return ! $this->passed($failOn);
    }

    public function matchesCount(): int
    {
        return count($this->matches);
    }
}
