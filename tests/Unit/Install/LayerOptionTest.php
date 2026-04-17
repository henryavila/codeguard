<?php

declare(strict_types=1);

use Henryavila\Codeguard\Install\LayerOption;

it('built-in layers report isBuiltIn() true', function (): void {
    expect(LayerOption::Domain->isBuiltIn())->toBeTrue()
        ->and(LayerOption::Application->isBuiltIn())->toBeTrue()
        ->and(LayerOption::Persistence->isBuiltIn())->toBeTrue();
});

it('Skip and Custom are not built-in layers', function (): void {
    expect(LayerOption::Skip->isBuiltIn())->toBeFalse()
        ->and(LayerOption::Custom->isBuiltIn())->toBeFalse();
});

it('every option provides a description and example', function (): void {
    foreach (LayerOption::cases() as $option) {
        expect($option->description())->not->toBe('')
            ->and($option->example())->not->toBe('');
    }
});

it('displayName renders friendly labels for sentinel options', function (): void {
    expect(LayerOption::Skip->displayName())->toBe('Skip')
        ->and(LayerOption::Custom->displayName())->toBe('Custom layer…')
        ->and(LayerOption::Domain->displayName())->toBe('Domain');
});

it('promptLabel for built-in layer includes the raw value', function (): void {
    expect(LayerOption::Domain->promptLabel())->toStartWith('Domain — ');
});

it('promptLabel for sentinel options uses displayName', function (): void {
    expect(LayerOption::Skip->promptLabel())->toStartWith('Skip — ')
        ->and(LayerOption::Custom->promptLabel())->toStartWith('Custom layer… — ');
});
