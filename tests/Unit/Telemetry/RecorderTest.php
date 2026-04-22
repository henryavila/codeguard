<?php

declare(strict_types=1);

use Henryavila\Codeguard\Telemetry\ConfigGate;
use Henryavila\Codeguard\Telemetry\EventName;
use Henryavila\Codeguard\Telemetry\EventStatus;
use Henryavila\Codeguard\Telemetry\FieldAllowlist;
use Henryavila\Codeguard\Telemetry\JsonlWriter;
use Henryavila\Codeguard\Telemetry\Recorder;
use Henryavila\Codeguard\Telemetry\Rotator;

function recorderTempDir(): string
{
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-recorder-'.uniqid();
    mkdir($dir, 0o755, recursive: true);

    return $dir;
}

function recorderCleanup(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        @unlink($dir.DIRECTORY_SEPARATOR.$entry);
    }
    @rmdir($dir);
}

function makeRecorder(
    string $activePath,
    bool $enabled = true,
    bool $strict = true,
): Recorder {
    $clock = static fn (): DateTimeImmutable => new DateTimeImmutable('2026-04-22 06:30:00-03:00');

    return new Recorder(
        gate: new ConfigGate(enabled: $enabled),
        allowlist: new FieldAllowlist(strictMode: $strict),
        rotator: new Rotator(maxBytes: 10 * 1024 * 1024, retain: 5, clock: $clock),
        writer: new JsonlWriter,
        activePath: $activePath,
        clock: $clock,
    );
}

/**
 * @return array<int, array<string, mixed>>
 */
function recorderReadLines(string $path): array
{
    if (! file_exists($path)) {
        return [];
    }

    /** @var array<int, array<string, mixed>> $lines */
    $lines = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $raw) {
        $decoded = json_decode((string) $raw, true);
        if (is_array($decoded)) {
            $lines[] = $decoded;
        }
    }

    return $lines;
}

// -----------------------------------------------------------------------------
// Gate behaviour
// -----------------------------------------------------------------------------

it('writes nothing when telemetry is disabled', function (): void {
    $dir = recorderTempDir();
    $path = $dir.DIRECTORY_SEPARATOR.'telemetry.jsonl';

    try {
        $recorder = makeRecorder($path, enabled: false);
        $recorder->record(EventName::CommandStart, EventStatus::Ok, 0, [
            'command' => 'install',
            'preset_flag' => 'default',
        ]);

        expect(file_exists($path))->toBeFalse();
    } finally {
        recorderCleanup($dir);
    }
});

// -----------------------------------------------------------------------------
// Happy path
// -----------------------------------------------------------------------------

it('records a well-formed event as one jsonl line', function (): void {
    $dir = recorderTempDir();
    $path = $dir.DIRECTORY_SEPARATOR.'telemetry.jsonl';

    try {
        $recorder = makeRecorder($path, enabled: true);
        $recorder->record(EventName::GateEnded, EventStatus::Ok, 4230, [
            'gate' => 'phpstan',
            'context' => 'pre-commit',
            'violations_count' => 0,
            'files_scanned_count' => 3,
        ]);

        $lines = recorderReadLines($path);
        expect($lines)->toHaveCount(1);

        $line = $lines[0];
        expect($line['event'])->toBe('gate.ended')
            ->and($line['status'])->toBe('ok')
            ->and($line['duration_ms'])->toBe(4230)
            ->and($line['gate'])->toBe('phpstan')
            ->and($line)->toHaveKey('ts')
            ->and($line['ts'])->toStartWith('2026-04-22T06:30:00');
    } finally {
        recorderCleanup($dir);
    }
});

it('records events in emission order', function (): void {
    $dir = recorderTempDir();
    $path = $dir.DIRECTORY_SEPARATOR.'telemetry.jsonl';

    try {
        $recorder = makeRecorder($path, enabled: true);
        $recorder->record(EventName::CommandStart, EventStatus::Ok, 0, ['command' => 'install', 'preset_flag' => 'default']);
        $recorder->record(EventName::GateStarted, EventStatus::Ok, 0, ['gate' => 'pint', 'context' => 'manual']);
        $recorder->record(EventName::GateEnded, EventStatus::Ok, 12, ['gate' => 'pint', 'context' => 'manual', 'violations_count' => 0, 'files_scanned_count' => 1]);
        $recorder->record(EventName::CommandEnd, EventStatus::Ok, 42, ['command' => 'install', 'exit_code' => 0]);

        $events = array_map(static fn (array $l): string => (string) ($l['event'] ?? ''), recorderReadLines($path));
        expect($events)->toBe(['command.start', 'gate.started', 'gate.ended', 'command.end']);
    } finally {
        recorderCleanup($dir);
    }
});

// -----------------------------------------------------------------------------
// Strict mode — allowlist throws, Recorder swallows, no line written
// -----------------------------------------------------------------------------

it('swallows strict-mode violations and writes nothing', function (): void {
    $dir = recorderTempDir();
    $path = $dir.DIRECTORY_SEPARATOR.'telemetry.jsonl';

    try {
        $recorder = makeRecorder($path, enabled: true, strict: true);
        $recorder->record(EventName::CommandStart, EventStatus::Ok, 0, [
            'command' => 'install',
            'unexpected_field' => 'whatever',
        ]);

        expect(file_exists($path))->toBeFalse();
    } finally {
        recorderCleanup($dir);
    }
});

// -----------------------------------------------------------------------------
// Non-strict mode — drops invalids + emits telemetry.dropped_field
// -----------------------------------------------------------------------------

it('non-strict mode drops invalid field and writes line without it', function (): void {
    $dir = recorderTempDir();
    $path = $dir.DIRECTORY_SEPARATOR.'telemetry.jsonl';

    try {
        $recorder = makeRecorder($path, enabled: true, strict: false);
        // pass a valid extras key with invalid value — the key 'gate'
        // exists in schema, so the meta event can reference it.
        $recorder->record(EventName::GateEnded, EventStatus::Ok, 100, [
            'gate' => 'phpstan',
            'context' => 'pre-commit',
            'violations_count' => 'not-an-int', // invalid type
            'files_scanned_count' => 5,
        ]);

        $lines = recorderReadLines($path);
        expect($lines)->toHaveCount(2);

        // First line: main event sans dropped field.
        expect($lines[0]['event'])->toBe('gate.ended')
            ->and($lines[0])->not->toHaveKey('violations_count')
            ->and($lines[0]['files_scanned_count'])->toBe(5);

        // Second line: meta event with target_event + field_name.
        expect($lines[1]['event'])->toBe('telemetry.dropped_field')
            ->and($lines[1]['target_event'])->toBe('gate.ended')
            ->and($lines[1]['field_name'])->toBe('violations_count');
    } finally {
        recorderCleanup($dir);
    }
});

it('does not emit meta when dropped key is not declared anywhere', function (): void {
    $dir = recorderTempDir();
    $path = $dir.DIRECTORY_SEPARATOR.'telemetry.jsonl';

    try {
        $recorder = makeRecorder($path, enabled: true, strict: false);
        $recorder->record(EventName::CommandStart, EventStatus::Ok, 0, [
            'command' => 'install',
            'rogue_unknown_key' => 'anything',
        ]);

        $lines = recorderReadLines($path);
        // Only the root event envelope; no meta emitted for truly-unknown key.
        expect($lines)->toHaveCount(1)
            ->and($lines[0]['event'])->toBe('command.start')
            ->and($lines[0])->not->toHaveKey('rogue_unknown_key');
    } finally {
        recorderCleanup($dir);
    }
});

it('does not recursively emit meta for a dropped field inside a meta event', function (): void {
    // Loop guard test: if the meta event itself somehow has dropped fields,
    // it must not trigger ANOTHER meta event.
    $dir = recorderTempDir();
    $path = $dir.DIRECTORY_SEPARATOR.'telemetry.jsonl';

    try {
        $recorder = makeRecorder($path, enabled: true, strict: false);
        $recorder->record(EventName::TelemetryDroppedField, EventStatus::Skip, 0, [
            'target_event' => 'gate.ended',
            'field_name' => 'violations_count',
            'extra_junk' => 1, // would be dropped
        ]);

        $lines = recorderReadLines($path);
        // exactly one envelope; loop guard prevented meta-meta recursion.
        expect($lines)->toHaveCount(1)
            ->and($lines[0]['event'])->toBe('telemetry.dropped_field');
    } finally {
        recorderCleanup($dir);
    }
});
