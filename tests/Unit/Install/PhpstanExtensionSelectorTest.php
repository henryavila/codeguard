<?php

declare(strict_types=1);

use Henryavila\Codeguard\Install\PhpstanExtension;
use Henryavila\Codeguard\Install\PhpstanExtensionSelector;

beforeEach(function (): void {
    $this->selector = new PhpstanExtensionSelector();
});

it('autoResolve returns defaultEnabled when no saved choice', function (): void {
    $result = $this->selector->autoResolve([]);

    expect($result)->toBe(PhpstanExtension::defaultEnabled());
});

it('autoResolve returns saved choice verbatim when present', function (): void {
    $saved = [PhpstanExtension::Larastan, PhpstanExtension::PhpUnit];

    $result = $this->selector->autoResolve($saved);

    expect($result)->toHaveCount(2)
        ->and($result[0])->toBe(PhpstanExtension::Larastan)
        ->and($result[1])->toBe(PhpstanExtension::PhpUnit);
});

it('autoResolve auto-enables DisallowedCalls when TestQuality selected', function (): void {
    $saved = [PhpstanExtension::TestQuality];

    $result = $this->selector->autoResolve($saved);

    expect($result)->toContain(PhpstanExtension::TestQuality)
        ->and($result)->toContain(PhpstanExtension::DisallowedCalls);
});

it('autoResolve does not duplicate DisallowedCalls if already present', function (): void {
    $saved = [PhpstanExtension::DisallowedCalls, PhpstanExtension::TestQuality];

    $result = $this->selector->autoResolve($saved);

    $disallowedCount = count(array_filter(
        $result,
        fn (PhpstanExtension $ext): bool => $ext === PhpstanExtension::DisallowedCalls,
    ));

    expect($disallowedCount)->toBe(1);
});
