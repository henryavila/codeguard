<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Analyze;

/**
 * Pattern / finding severity. Ordered so `--fail-on` can gate the exit code
 * by comparing a finding's severity against a threshold.
 */
enum Severity: string
{
    case Critical = 'critical';
    case Warning = 'warning';
    case Suggestion = 'suggestion';

    /**
     * Higher = more severe. Used for `--fail-on` threshold comparison.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Critical => 3,
            self::Warning => 2,
            self::Suggestion => 1,
        };
    }

    /**
     * True when this severity is at least as severe as $threshold.
     */
    public function meets(self $threshold): bool
    {
        return $this->weight() >= $threshold->weight();
    }
}
