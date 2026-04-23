<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Testing;

interface AsyncCommandExecutor extends CommandExecutor
{
    /**
     * Start a command in the background without blocking.
     *
     * @param  list<string>  $command
     */
    public function start(array $command): RunningCommand;
}
