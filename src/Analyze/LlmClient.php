<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Analyze;

use Henryavila\Codeguard\Testing\CommandExecutor;

/**
 * The Node-free LLM transport seam. Mirrors the
 * {@see CommandExecutor} pattern: a narrow
 * interface with a real driver, a null default, and a test fake.
 */
interface LlmClient
{
    /**
     * Review one file against its matched patterns; return raw findings
     * (FindingSchema-shaped arrays) for the trust boundary to validate.
     *
     * @return list<array<string, mixed>>
     */
    public function review(AnalysisUnit $unit, string $systemPrompt): array;

    /**
     * Whether a real adjudicating driver is configured. False for the null
     * driver, which lets the command print an honest degradation notice
     * instead of reporting an unadjudicated repo as clean.
     */
    public function isConfigured(): bool;
}
