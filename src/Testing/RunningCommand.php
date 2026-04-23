<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Testing;

interface RunningCommand
{
    public function wait(?callable $writeOutput = null): int;

    public function isRunning(): bool;

    public function getExitCode(): ?int;

    public function getOutput(): string;

    public function getErrorOutput(): string;

    public function stop(int $timeout = 10): void;
}
