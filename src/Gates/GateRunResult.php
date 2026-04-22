<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Gates;

/**
 * Outcome of running a single gate via {@see GateRunner}.
 *
 * `gateKey` is the config slot (e.g. 'phpstan') — the command resolves
 * it to the display label and to the Telemetry enum value in lockstep.
 * `exitCode` follows Unix convention: 0 = pass, non-zero = fail.
 * `durationMs` is wall-clock in milliseconds, measured with hrtime.
 */
final readonly class GateRunResult
{
    public function __construct(
        public string $gateKey,
        public int $exitCode,
        public int $durationMs,
    ) {}

    public function passed(): bool
    {
        return $this->exitCode === 0;
    }

    public function failed(): bool
    {
        return $this->exitCode !== 0;
    }
}
