<?php

declare(strict_types=1);

use Henryavila\Codeguard\Install\EnvironmentInfo;
use Henryavila\Codeguard\Install\PresetSelector;
use Henryavila\Codeguard\Testing\Preset;

function selectorEnv(bool $hasPackageJson = false, bool $hasNodeModules = false): EnvironmentInfo
{
    return new EnvironmentInfo(
        phpVersion: '8.3.0',
        composerVersion: '2.7.0',
        nodeVersion: $hasPackageJson || $hasNodeModules ? '20.0.0' : null,
        hasPackageJson: $hasPackageJson,
        hasNodeModules: $hasNodeModules,
        hasCaptainhookBinary: false,
    );
}

it('autoSelect returns Full when the project uses Node', function (): void {
    $selector = new PresetSelector;

    expect($selector->autoSelect(selectorEnv(hasPackageJson: true)))->toBe(Preset::Full);
});

it('autoSelect returns Default when the project does not use Node', function (): void {
    $selector = new PresetSelector;

    expect($selector->autoSelect(selectorEnv()))->toBe(Preset::Default);
});

it('resolveFromFlag understands "default"', function (): void {
    expect((new PresetSelector)->resolveFromFlag('default'))->toBe(Preset::Default);
});

it('resolveFromFlag understands "codeguard"', function (): void {
    expect((new PresetSelector)->resolveFromFlag('codeguard'))->toBe(Preset::Default);
});

it('resolveFromFlag understands "full"', function (): void {
    expect((new PresetSelector)->resolveFromFlag('full'))->toBe(Preset::Full);
});

it('resolveFromFlag understands "codeguard-full"', function (): void {
    expect((new PresetSelector)->resolveFromFlag('codeguard-full'))->toBe(Preset::Full);
});

it('resolveFromFlag throws for unknown flags', function (): void {
    (new PresetSelector)->resolveFromFlag('mystery');
})->throws(InvalidArgumentException::class, "Unknown preset 'mystery'");
