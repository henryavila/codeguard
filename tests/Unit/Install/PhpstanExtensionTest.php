<?php

declare(strict_types=1);

use Henryavila\Codeguard\Install\PhpstanExtension;

it('every extension has a non-empty display name and description', function (): void {
    foreach (PhpstanExtension::cases() as $extension) {
        expect($extension->displayName())->not->toBe('')
            ->and($extension->description())->not->toBe('');
    }
});

it('TestQuality declares DisallowedCalls as dependency', function (): void {
    expect(PhpstanExtension::TestQuality->isDependOn())->toBe(PhpstanExtension::DisallowedCalls);
});

it('other extensions have no dependency', function (): void {
    expect(PhpstanExtension::Larastan->isDependOn())->toBeNull()
        ->and(PhpstanExtension::PhpUnit->isDependOn())->toBeNull()
        ->and(PhpstanExtension::CognitiveComplexity->isDependOn())->toBeNull()
        ->and(PhpstanExtension::DeadCode->isDependOn())->toBeNull()
        ->and(PhpstanExtension::DisallowedCalls->isDependOn())->toBeNull();
});

it('defaultEnabled excludes Peststan (opt-in via Pest auto-detect)', function (): void {
    $defaults = PhpstanExtension::defaultEnabled();

    expect($defaults)->not->toContain(PhpstanExtension::Peststan);
    // Every other case should still be enabled by default.
    foreach (PhpstanExtension::cases() as $case) {
        if ($case === PhpstanExtension::Peststan) {
            continue;
        }
        expect($defaults)->toContain($case);
    }
});
