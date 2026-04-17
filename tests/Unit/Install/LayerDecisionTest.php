<?php

declare(strict_types=1);

use Henryavila\Codeguard\Install\LayerDecision;

it('skip() creates a null-layer decision', function (): void {
    $decision = LayerDecision::skip('App\\Features');

    expect($decision->namespace)->toBe('App\\Features')
        ->and($decision->layerName)->toBeNull()
        ->and($decision->isSkipped())->toBeTrue();
});

it('assign() attaches a layer name', function (): void {
    $decision = LayerDecision::assign('App\\Domain', 'Domain');

    expect($decision->namespace)->toBe('App\\Domain')
        ->and($decision->layerName)->toBe('Domain')
        ->and($decision->isSkipped())->toBeFalse();
});
