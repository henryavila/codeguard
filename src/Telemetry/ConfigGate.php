<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Telemetry;

/**
 * Single-decision gate for whether telemetry is enabled in this process.
 *
 * Bound as a singleton in the service container so the `enabled` flag is
 * read from config exactly once at boot. Downstream services ask
 * `isEnabled()` to short-circuit expensive work.
 */
final class ConfigGate
{
    public function __construct(
        private readonly bool $enabled,
    ) {}

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
