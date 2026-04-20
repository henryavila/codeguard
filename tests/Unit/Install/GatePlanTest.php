<?php

declare(strict_types=1);

use Henryavila\Codeguard\Install\GatePlan;

it('configTimeLabel returns "0" for zero minutes', function (): void {
    $plan = new GatePlan('Pint', 'auto-format', configMinutes: 0, ciCostSeconds: 0);

    expect($plan->configTimeLabel())->toBe('0');
});

it('configTimeLabel shows minutes when under 60', function (): void {
    $plan = new GatePlan('PHPStan', 'type safety', configMinutes: 15, ciCostSeconds: 30);

    expect($plan->configTimeLabel())->toBe('15min');
});

it('configTimeLabel shows whole hours without trailing minutes', function (): void {
    $plan = new GatePlan('Deptrac', 'architecture', configMinutes: 60, ciCostSeconds: 0);

    expect($plan->configTimeLabel())->toBe('1h');
});

it('configTimeLabel combines hours and minutes when remainder is non-zero', function (): void {
    $plan = new GatePlan('Infection', 'test quality', configMinutes: 75, ciCostSeconds: 0);

    expect($plan->configTimeLabel())->toBe('1h 15min');
});

it('ciCostLabel returns "0" for zero seconds', function (): void {
    $plan = new GatePlan('CaptainHook', 'pre-commit', configMinutes: 0, ciCostSeconds: 0);

    expect($plan->ciCostLabel())->toBe('0');
});

it('ciCostLabel shows seconds when under 60', function (): void {
    $plan = new GatePlan('Pint', 'auto-format', configMinutes: 0, ciCostSeconds: 5);

    expect($plan->ciCostLabel())->toBe('~5s');
});

it('ciCostLabel shows whole minutes when seconds divide evenly', function (): void {
    $plan = new GatePlan('Infection', 'test quality', configMinutes: 20, ciCostSeconds: 180);

    expect($plan->ciCostLabel())->toBe('+3min');
});

it('ciCostLabel combines minutes and residual seconds', function (): void {
    $plan = new GatePlan('Slow', 'slow gate', configMinutes: 0, ciCostSeconds: 125);

    expect($plan->ciCostLabel())->toBe('+2min 5s');
});
