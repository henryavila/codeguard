<?php

declare(strict_types=1);

use Henryavila\Codeguard\Testing\Preset;

it('has two cases with expected scalar values', function (): void {
    expect(Preset::Default->value)->toBe('codeguard')
        ->and(Preset::Full->value)->toBe('codeguard-full');
});

it('reports requiresNode() true only for Full', function (): void {
    expect(Preset::Default->requiresNode())->toBeFalse()
        ->and(Preset::Full->requiresNode())->toBeTrue();
});

it('returns a human-readable label for Default', function (): void {
    expect(Preset::Default->label())->toBe('codeguard (PHP-native, 5 gates)');
});

it('returns a human-readable label for Full', function (): void {
    expect(Preset::Full->label())
        ->toBe('codeguard-full (includes Node-based jscpd, 8 gates)');
});

it('enabledGateKeys for Default returns 5 PHP-native gates', function (): void {
    expect(Preset::Default->enabledGateKeys())
        ->toBe(['audit', 'pint', 'phpstan', 'deptrac', 'infection']);
});

it('enabledGateKeys for Full adds jscpd and insights', function (): void {
    expect(Preset::Full->enabledGateKeys())->toBe([
        'audit', 'pint', 'phpstan', 'deptrac', 'infection', 'jscpd', 'insights',
    ]);
});
