<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Testing;

class TestRunResult
{
    /**
     * @param  list<TestStageResult>  $stages
     */
    public function __construct(
        private readonly array $stages,
        private readonly ?int $durationMs = null,
        private readonly ?string $logFilePath = null,
    ) {}

    /**
     * @return list<TestStageResult>
     */
    public function stages(): array
    {
        return $this->stages;
    }

    public function hasFailures(): bool
    {
        foreach ($this->stages as $stage) {
            if ($stage->hasFailures()) {
                return true;
            }
        }

        return false;
    }

    public function stage(string $key): ?TestStageResult
    {
        foreach ($this->stages as $stage) {
            if ($stage->key === $key) {
                return $stage;
            }
        }

        return null;
    }

    public function logFilePath(): ?string
    {
        return $this->logFilePath;
    }

    public function durationMs(): int
    {
        if ($this->durationMs !== null) {
            return $this->durationMs;
        }

        return array_sum(array_map(
            static fn (TestStageResult $stage): int => $stage->durationMs,
            $this->stages,
        ));
    }
}
