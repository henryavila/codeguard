<?php

declare(strict_types=1);

use Henryavila\Codeguard\Gates\MutationGateResolver;
use Henryavila\Codeguard\Testing\GateConfig;

function infectionGate(string $command = './vendor/bin/infection --min-msi=60 --min-covered-msi=70 --no-progress'): GateConfig
{
    return new GateConfig(
        key: 'infection',
        enabled: true,
        command: $command,
        description: 'Infection mutation testing',
    );
}

it('leaves the infection gate untouched when the project does not use Pest', function (): void {
    $resolver = new MutationGateResolver(usesPest: false);

    $resolved = $resolver->resolve(infectionGate());

    expect($resolved->command)->toBe('./vendor/bin/infection --min-msi=60 --min-covered-msi=70 --no-progress');
});

it('rewrites the infection gate to pest --mutate when the project uses Pest', function (): void {
    $resolver = new MutationGateResolver(usesPest: true);

    $resolved = $resolver->resolve(infectionGate());

    expect($resolved->command)->toBe('./vendor/bin/pest --mutate --min=60');
});

it('maps the infection min-msi threshold onto pest --min', function (): void {
    $resolver = new MutationGateResolver(usesPest: true);

    $resolved = $resolver->resolve(infectionGate('./vendor/bin/infection --min-msi=85 --no-progress'));

    expect($resolved->command)->toBe('./vendor/bin/pest --mutate --min=85');
});

it('omits --min when the infection command has no min-msi flag', function (): void {
    $resolver = new MutationGateResolver(usesPest: true);

    $resolved = $resolver->resolve(infectionGate('./vendor/bin/infection --no-progress'));

    expect($resolved->command)->toBe('./vendor/bin/pest --mutate');
});

it('preserves the gate key and enabled flag so telemetry stays stable', function (): void {
    $resolver = new MutationGateResolver(usesPest: true);

    $resolved = $resolver->resolve(infectionGate());

    expect($resolved->key)->toBe('infection')
        ->and($resolved->enabled)->toBeTrue();
});

it('annotates the description so the Pest path is visible in output', function (): void {
    $resolver = new MutationGateResolver(usesPest: true);

    $resolved = $resolver->resolve(infectionGate());

    expect($resolved->description)->toContain('Pest');
});

it('does not touch non-mutation gates even when Pest is in use', function (): void {
    $resolver = new MutationGateResolver(usesPest: true);

    $pint = new GateConfig(
        key: 'pint',
        enabled: true,
        command: './vendor/bin/pint --test',
        description: 'Laravel Pint (code style check)',
    );

    $resolved = $resolver->resolve($pint);

    expect($resolved->command)->toBe('./vendor/bin/pint --test')
        ->and($resolved->description)->toBe('Laravel Pint (code style check)');
});
