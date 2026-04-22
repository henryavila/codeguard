<?php

declare(strict_types=1);

use Henryavila\Codeguard\Telemetry\EventName;

it('exposes exactly 20 canonical event names', function (): void {
    expect(EventName::cases())->toHaveCount(20);
});

it('maps every case to a dot-delimited string value', function (EventName $event): void {
    expect($event->value)->toMatch('/^[a-z]+(\.[a-z_]+)+$/');
})->with(EventName::cases());

it('has distinct backing values', function (): void {
    $values = array_map(static fn (EventName $e): string => $e->value, EventName::cases());
    expect($values)->toBe(array_values(array_unique($values)));
});

it('contains the 7 required layer coverage', function (): void {
    $values = array_map(static fn (EventName $e): string => $e->value, EventName::cases());

    // Spec §5.2 expects one entry per checkpoint below.
    expect($values)->toContain('command.start', 'command.end')
        ->and($values)->toContain('install.env.detected', 'install.captainhook.activated')
        ->and($values)->toContain('gate.started', 'gate.ended')
        ->and($values)->toContain('hook.triggered', 'hook.completed')
        ->and($values)->toContain('test.started', 'test.ended')
        ->and($values)->toContain('analyze.ended', 'baseline.ended')
        ->and($values)->toContain('prepare.step.ended')
        ->and($values)->toContain('telemetry.dropped_field');
});
