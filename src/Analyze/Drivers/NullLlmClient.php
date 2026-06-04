<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Analyze\Drivers;

use Henryavila\Codeguard\Analyze\AnalysisUnit;
use Henryavila\Codeguard\Analyze\LlmClient;

/**
 * The bound default when no real driver is configured. Adjudicates nothing;
 * the runner detects {@see isConfigured()} === false and emits the
 * degradation path (notice + telemetry status Skip) rather than a false pass.
 */
final class NullLlmClient implements LlmClient
{
    public function review(AnalysisUnit $unit, string $systemPrompt): array
    {
        return [];
    }

    public function isConfigured(): bool
    {
        return false;
    }
}
