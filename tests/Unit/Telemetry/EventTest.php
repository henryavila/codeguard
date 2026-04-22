<?php

declare(strict_types=1);

use Henryavila\Codeguard\Telemetry\Event;
use Henryavila\Codeguard\Telemetry\EventName;
use Henryavila\Codeguard\Telemetry\EventStatus;

it('serializes to a canonical ordered array', function (): void {
    $event = new Event(
        ts: '2026-04-22T06:30:00.000-03:00',
        event: EventName::GateEnded,
        status: EventStatus::Ok,
        durationMs: 4230,
        extras: [
            'gate' => 'phpstan',
            'context' => 'pre-commit',
            'violations_count' => 0,
            'files_scanned_count' => 3,
        ],
    );

    expect(array_keys($event->toArray()))->toBe([
        'ts',
        'event',
        'status',
        'duration_ms',
        'gate',
        'context',
        'violations_count',
        'files_scanned_count',
    ]);
});

it('resolves enum backing values in toArray', function (): void {
    $event = new Event(
        ts: '2026-04-22T06:30:00.000-03:00',
        event: EventName::CommandStart,
        status: EventStatus::Skip,
        durationMs: 0,
    );

    $payload = $event->toArray();
    expect($payload['event'])->toBe('command.start')
        ->and($payload['status'])->toBe('skip')
        ->and($payload['duration_ms'])->toBe(0);
});

it('allows empty extras', function (): void {
    $event = new Event(
        ts: '2026-04-22T06:30:00.000-03:00',
        event: EventName::CommandEnd,
        status: EventStatus::Ok,
        durationMs: 12,
    );

    expect($event->toArray())->toBe([
        'ts' => '2026-04-22T06:30:00.000-03:00',
        'event' => 'command.end',
        'status' => 'ok',
        'duration_ms' => 12,
    ]);
});

it('is a readonly value object (every property is readonly)', function (): void {
    $reflection = new ReflectionClass(Event::class);

    foreach ($reflection->getProperties() as $property) {
        expect($property->isReadOnly())
            ->toBeTrue("property {$property->getName()} must be readonly");
    }
});

it('serializes as single-line JSON without escapes', function (): void {
    $event = new Event(
        ts: '2026-04-22T06:30:00.000-03:00',
        event: EventName::InstallPresetSelected,
        status: EventStatus::Ok,
        durationMs: 5,
        extras: ['preset' => 'default', 'source' => 'auto'],
    );

    $line = json_encode($event->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    expect($line)->toBeString()
        ->and(str_contains((string) $line, "\n"))->toBeFalse()
        ->and($line)->toContain('"event":"install.preset.selected"');
});
