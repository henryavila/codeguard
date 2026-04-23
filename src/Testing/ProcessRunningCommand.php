<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Testing;

use Symfony\Component\Process\Process;

class ProcessRunningCommand implements RunningCommand
{
    public function __construct(
        private readonly Process $process,
    ) {}

    public function wait(?callable $writeOutput = null): int
    {
        if ($writeOutput !== null) {
            $this->process->wait(function (string $_type, string $buffer) use ($writeOutput): void {
                $writeOutput($buffer);
            });
        } else {
            $this->process->wait();
        }

        return $this->process->getExitCode() ?? 1;
    }

    public function isRunning(): bool
    {
        return $this->process->isRunning();
    }

    public function getExitCode(): ?int
    {
        return $this->process->getExitCode();
    }

    public function getOutput(): string
    {
        return $this->process->getOutput();
    }

    public function getErrorOutput(): string
    {
        return $this->process->getErrorOutput();
    }

    public function stop(int $timeout = 10): void
    {
        $this->process->stop($timeout);
    }
}
