<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Testing;

use Symfony\Component\Process\Process;

class ProcessCommandExecutor implements AsyncCommandExecutor
{
    public function run(array $command, ?callable $writeOutput = null): int
    {
        $process = new Process(
            command: $command,
            cwd: base_path(),
            env: ['APP_ENV' => 'testing'],
        );

        $process->setTimeout(null);
        $process->run(function (string $_type, string $buffer) use ($writeOutput): void {
            if ($writeOutput !== null) {
                $writeOutput($buffer);
            }
        });

        return $process->getExitCode() ?? 1;
    }

    public function start(array $command): RunningCommand
    {
        $process = new Process(
            command: $command,
            cwd: base_path(),
            env: ['APP_ENV' => 'testing'],
        );

        $process->setTimeout(null);
        $process->start();

        return new ProcessRunningCommand($process);
    }
}
