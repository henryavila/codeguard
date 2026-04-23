<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Tests\Support;

use Henryavila\Codeguard\Testing\RunningCommand;

final class FakeRunningCommand implements RunningCommand
{
    public function __construct(
        private readonly int $exitCode,
        private readonly string $output = '',
    ) {}

    public function wait(?callable $writeOutput = null): int
    {
        if ($writeOutput !== null && $this->output !== '') {
            $writeOutput($this->output);
        }

        return $this->exitCode;
    }

    public function isRunning(): bool
    {
        return false;
    }

    public function getExitCode(): ?int
    {
        return $this->exitCode;
    }

    public function getOutput(): string
    {
        return $this->output;
    }

    public function getErrorOutput(): string
    {
        return '';
    }

    public function stop(int $timeout = 10): void {}
}
