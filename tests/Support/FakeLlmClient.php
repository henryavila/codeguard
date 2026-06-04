<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Tests\Support;

use Closure;
use Henryavila\Codeguard\Analyze\AnalysisUnit;
use Henryavila\Codeguard\Analyze\LlmClient;

/**
 * Deterministic, network-free LlmClient for tests. Mirrors
 * {@see FakeCommandExecutor}: a Closure handler + a record of every call.
 */
final class FakeLlmClient implements LlmClient
{
    /** @var list<AnalysisUnit> */
    public array $calls = [];

    /**
     * @param  Closure(AnalysisUnit): list<array<string, mixed>>  $handler
     */
    public function __construct(
        private readonly Closure $handler,
        private readonly bool $configured = true,
    ) {}

    public function review(AnalysisUnit $unit, string $systemPrompt): array
    {
        $this->calls[] = $unit;

        return ($this->handler)($unit);
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }
}
