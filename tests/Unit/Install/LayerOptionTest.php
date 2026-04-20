<?php

declare(strict_types=1);

use Henryavila\Codeguard\Install\LayerOption;

it('built-in layers report isBuiltIn() true', function (): void {
    expect(LayerOption::Domain->isBuiltIn())->toBeTrue()
        ->and(LayerOption::Application->isBuiltIn())->toBeTrue()
        ->and(LayerOption::Presentation->isBuiltIn())->toBeTrue()
        ->and(LayerOption::Infrastructure->isBuiltIn())->toBeTrue();
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
    expect(LayerOption::Domain->promptLabel())->toStartWith('Domain — ')
        ->and(LayerOption::Presentation->promptLabel())->toStartWith('Presentation — ')
        ->and(LayerOption::Infrastructure->promptLabel())->toStartWith('Infrastructure — ');
});

it('promptLabel for sentinel options uses displayName', function (): void {
    expect(LayerOption::Skip->promptLabel())->toStartWith('Skip — ')
        ->and(LayerOption::Custom->promptLabel())->toStartWith('Custom layer… — ');
});

it('concreteLayers returns the four built-in layers in wizard order', function (): void {
    expect(LayerOption::concreteLayers())->toBe([
        LayerOption::Domain,
        LayerOption::Application,
        LayerOption::Presentation,
        LayerOption::Infrastructure,
    ]);
});

it('skipGuidance explains when to use Skip and its impact', function (): void {
    $guidance = LayerOption::skipGuidance();

    expect($guidance)->toContain('When to use Skip')
        ->and($guidance)->toContain('Providers')
        ->and($guidance)->toContain('Exceptions')
        ->and($guidance)->toContain('Traits')
        ->and($guidance)->toContain('Helpers')
        ->and($guidance)->toContain('false positives');
});

it('typicalHint tailors copy per layer', function (): void {
    expect(LayerOption::Domain->typicalHint('App\\Policies'))
        ->toContain('Domain')
        ->and(LayerOption::Presentation->typicalHint('App\\Livewire'))
        ->toContain('Presentation')
        ->and(LayerOption::Infrastructure->typicalHint('App\\Models'))
        ->toContain('Infrastructure');
});
