<?php

declare(strict_types=1);

use Henryavila\Codeguard\Telemetry\ConfigGate;

it('returns true when constructed enabled', function (): void {
    $gate = new ConfigGate(enabled: true);
    expect($gate->isEnabled())->toBeTrue();
});

it('returns false when constructed disabled', function (): void {
    $gate = new ConfigGate(enabled: false);
    expect($gate->isEnabled())->toBeFalse();
});

it('is immutable after construction', function (): void {
    $reflection = new ReflectionClass(ConfigGate::class);
    foreach ($reflection->getProperties() as $property) {
        expect($property->isReadOnly())->toBeTrue();
    }
});
