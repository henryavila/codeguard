<?php

declare(strict_types=1);

use Henryavila\Codeguard\Install\GatePlan;
use Henryavila\Codeguard\Install\GatePlanRegistry;
use Henryavila\Codeguard\Testing\Preset;

it('plansFor(Default) returns the 5 PHP-native gates', function (): void {
    $plans = (new GatePlanRegistry())->plansFor(Preset::Default);

    expect($plans)->toHaveCount(5);

    $names = array_map(static fn (GatePlan $p): string => $p->gateName, $plans);
    expect($names)->toBe(['Pint', 'PHPStan', 'Deptrac', 'Infection', 'Lefthook']);
});

it('plansFor(Full) returns the 8 extended gates', function (): void {
    $plans = (new GatePlanRegistry())->plansFor(Preset::Full);

    expect($plans)->toHaveCount(8);

    $names = array_map(static fn (GatePlan $p): string => $p->gateName, $plans);
    expect($names)->toBe([
        'Pint', 'PHPStan', 'Deptrac', 'Infection', 'Lefthook',
        'jscpd', 'Insights', 'TestQualityTest',
    ]);
});

it('totalConfigMinutes sums configMinutes across plans', function (): void {
    $registry = new GatePlanRegistry();

    $plans = [
        new GatePlan('A', 'desc', configMinutes: 15, ciCostSeconds: 0),
        new GatePlan('B', 'desc', configMinutes: 30, ciCostSeconds: 0),
        new GatePlan('C', 'desc', configMinutes: 5, ciCostSeconds: 0),
    ];

    expect($registry->totalConfigMinutes($plans))->toBe(50);
});

it('totalConfigMinutes returns 0 for an empty plan list', function (): void {
    expect((new GatePlanRegistry())->totalConfigMinutes([]))->toBe(0);
});

it('formatMinutes returns "0 min" when zero', function (): void {
    expect((new GatePlanRegistry())->formatMinutes(0))->toBe('0 min');
});

it('formatMinutes returns raw minutes when under an hour', function (): void {
    expect((new GatePlanRegistry())->formatMinutes(45))->toBe('45 min');
});

it('formatMinutes returns whole hours when remainder is zero', function (): void {
    expect((new GatePlanRegistry())->formatMinutes(120))->toBe('2h');
});

it('formatMinutes combines hours and remaining minutes', function (): void {
    expect((new GatePlanRegistry())->formatMinutes(75))->toBe('1h 15min');
});
