<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Tests\Support;

use Closure;
use Henryavila\Codeguard\Testing\AsyncCommandExecutor;
use Henryavila\Codeguard\Testing\RunningCommand;

final class FakeCommandExecutor implements AsyncCommandExecutor
{
    /** @var list<list<string>> */
    public array $executedCommands = [];

    /**
     * @param  Closure(list<string>): array{0: int, 1: string}  $handler
     */
    public function __construct(private readonly Closure $handler) {}

    public function run(array $command, ?callable $writeOutput = null): int
    {
        $this->executedCommands[] = $command;
        [$exit, $output] = ($this->handler)($command);

        if ($writeOutput !== null && $output !== '') {
            $writeOutput($output);
        }

        return $exit;
    }

    public function start(array $command): RunningCommand
    {
        $this->executedCommands[] = $command;
        [$exit, $output] = ($this->handler)($command);

        return new FakeRunningCommand($exit, $output);
    }
}
