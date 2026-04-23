<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Testing;

interface CommandExecutor
{
    /**
     * @param  list<string>  $command
     */
    public function run(array $command, ?callable $writeOutput = null): int;
}
