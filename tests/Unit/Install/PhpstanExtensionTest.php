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

it('defaultEnabled returns all cases (opinionated all-on)', function (): void {
    expect(PhpstanExtension::defaultEnabled())->toBe(PhpstanExtension::cases());
});
