<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Testing;

class TestStageResult
{
    /**
     * @param  list<string>  $command
     * @param  list<string>  $failedTests
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly array $command,
        public readonly int $exitCode,
        public readonly ?int $passed,
        public readonly ?int $failed,
        public readonly ?int $skipped,
        public readonly array $failedTests,
        public readonly int $durationMs = 0,
    ) {}

    public function status(): string
    {
        return $this->hasFailures() ? 'FAIL' : 'PASS';
    }

    public function hasFailures(): bool
    {
        if ($this->failed !== null && $this->failed > 0) {
            return true;
        }

        if ($this->failed !== null && $this->failed === 0 && $this->failedTests !== []) {
            return true;
        }

        return $this->exitCode !== 0;
    }
}
