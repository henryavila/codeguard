<?php

declare(strict_types=1);

use Henryavila\Codeguard\Install\NextStepsReporter;
use Henryavila\Codeguard\Testing\Preset;

/**
 * @param  list<array{gate: string, action: string, command: string}>  $steps
 * @return list<string>
 */
function nextStepCommands(array $steps): array
{
    return array_map(static fn (array $step): string => $step['command'], $steps);
}

it('suggests the infection binary for non-Pest projects', function (): void {
    $commands = nextStepCommands((new NextStepsReporter)->nextSteps(Preset::Default, usesPest: false));

    expect($commands)->toContain('./vendor/bin/infection --initial-tests-only');
    expect($commands)->not->toContain('./vendor/bin/pest --mutate');
});

it('suggests pest --mutate for Pest projects', function (): void {
    $commands = nextStepCommands((new NextStepsReporter)->nextSteps(Preset::Default, usesPest: true));

    expect($commands)->toContain('./vendor/bin/pest --mutate');
    expect($commands)->not->toContain('./vendor/bin/infection --initial-tests-only');
});

it('defaults to the infection binary when Pest usage is unspecified', function (): void {
    $steps = (new NextStepsReporter)->nextSteps(Preset::Default);

    expect(nextStepCommands($steps))->toContain('./vendor/bin/infection --initial-tests-only');
});
