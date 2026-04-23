<?php

declare(strict_types=1);

use Henryavila\Codeguard\Testing\ProcessCommandExecutor;
use Henryavila\Codeguard\Testing\ProcessRunningCommand;
use Henryavila\Codeguard\Testing\RunningCommand;

it('runs command synchronously and returns exit code 0 on success', function (): void {
    $executor = new ProcessCommandExecutor;

    $exit = $executor->run(['printf', 'hello']);

    expect($exit)->toBe(0);
});

it('returns non-zero exit code when command fails', function (): void {
    $executor = new ProcessCommandExecutor;

    $exit = $executor->run(['sh', '-c', 'exit 42']);

    expect($exit)->toBe(42);
});

it('streams output to writeOutput callback', function (): void {
    $executor = new ProcessCommandExecutor;
    $captured = '';

    $executor->run(['printf', 'streamed-output'], function (string $buf) use (&$captured): void {
        $captured .= $buf;
    });

    expect($captured)->toContain('streamed-output');
});

it('starts async command returning a RunningCommand', function (): void {
    $executor = new ProcessCommandExecutor;

    $running = $executor->start(['printf', 'async']);

    expect($running)->toBeInstanceOf(RunningCommand::class)
        ->and($running)->toBeInstanceOf(ProcessRunningCommand::class);

    $exit = $running->wait();

    expect($exit)->toBe(0)
        ->and($running->getOutput())->toContain('async');
});

it('tracks isRunning lifecycle of async command', function (): void {
    $executor = new ProcessCommandExecutor;

    $running = $executor->start(['sh', '-c', 'sleep 0.1; echo done']);

    // May or may not be running depending on scheduling, but wait() must settle it
    $running->wait();

    expect($running->isRunning())->toBeFalse()
        ->and($running->getExitCode())->toBe(0)
        ->and($running->getOutput())->toContain('done');
});

it('stops a long-running command', function (): void {
    $executor = new ProcessCommandExecutor;

    $running = $executor->start(['sh', '-c', 'sleep 5']);
    $running->stop(1);

    expect($running->isRunning())->toBeFalse();
});

it('streams output from wait() callback', function (): void {
    $executor = new ProcessCommandExecutor;
    $captured = '';

    $running = $executor->start(['printf', 'wait-stream']);
    $running->wait(function (string $buf) use (&$captured): void {
        $captured .= $buf;
    });

    expect($captured)->toContain('wait-stream');
});
