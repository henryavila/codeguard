<?php

declare(strict_types=1);

use Henryavila\Codeguard\Install\EnvironmentInfo;

function makeEnvInfo(
    ?string $nodeVersion = null,
    bool $hasPackageJson = false,
    bool $hasNodeModules = false,
): EnvironmentInfo {
    return new EnvironmentInfo(
        phpVersion: '8.3.0',
        composerVersion: '2.7.0',
        nodeVersion: $nodeVersion,
        hasPackageJson: $hasPackageJson,
        hasNodeModules: $hasNodeModules,
        hasLefthookBinary: false,
    );
}

it('hasNode returns true when a node version is present', function (): void {
    expect(makeEnvInfo(nodeVersion: '20.5.0')->hasNode())->toBeTrue();
});

it('hasNode returns false when node version is null', function (): void {
    expect(makeEnvInfo(nodeVersion: null)->hasNode())->toBeFalse();
});

it('usesNodeInProject is true when package.json exists', function (): void {
    expect(makeEnvInfo(hasPackageJson: true)->usesNodeInProject())->toBeTrue();
});

it('usesNodeInProject is true when node_modules exists', function (): void {
    expect(makeEnvInfo(hasNodeModules: true)->usesNodeInProject())->toBeTrue();
});

it('usesNodeInProject is false without package.json or node_modules', function (): void {
    expect(makeEnvInfo()->usesNodeInProject())->toBeFalse();
});

it('reports high confidence when project uses Node (package.json)', function (): void {
    $env = makeEnvInfo(nodeVersion: '20.5.0', hasPackageJson: true);

    expect($env->nodeConfidence())->toBe('high');
});

it('reports high confidence when project uses Node (node_modules)', function (): void {
    $env = makeEnvInfo(nodeVersion: '20.5.0', hasNodeModules: true);

    expect($env->nodeConfidence())->toBe('high');
});

it('reports medium confidence when node is global but project does not use it', function (): void {
    $env = makeEnvInfo(nodeVersion: '20.5.0');

    expect($env->nodeConfidence())->toBe('medium');
});

it('reports low confidence when no Node is present anywhere', function (): void {
    expect(makeEnvInfo()->nodeConfidence())->toBe('low');
});
