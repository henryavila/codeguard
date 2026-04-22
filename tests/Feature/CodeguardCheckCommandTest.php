<?php

declare(strict_types=1);

use Henryavila\Codeguard\Gates\GateRunner;
use Henryavila\Codeguard\Telemetry\ConfigGate;
use Henryavila\Codeguard\Telemetry\FieldAllowlist;
use Henryavila\Codeguard\Telemetry\JsonlWriter;
use Henryavila\Codeguard\Telemetry\Recorder;
use Henryavila\Codeguard\Telemetry\Rotator;

beforeEach(function (): void {
    // Disable all gates by default; individual tests re-enable what they want
    // via config()->set so fail-fast + filter behaviour is exercised in
    // isolation. Commands are deterministic: `exit 0` / `exit 1`.
    config()->set('codeguard.gates', [
        'pint' => ['enabled' => true, 'command' => 'exit 0', 'description' => 'pint (passing)'],
        'phpstan' => ['enabled' => true, 'command' => 'exit 0', 'description' => 'phpstan (passing)'],
        'deptrac' => ['enabled' => true, 'command' => 'exit 0', 'description' => 'deptrac (passing)'],
    ]);

    // Point the telemetry recorder at a temp path so we can inspect events.
    $this->telemetryPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-check-feature-'.uniqid().'.jsonl';

    $this->app->singleton(ConfigGate::class, fn (): ConfigGate => new ConfigGate(enabled: true));
    $this->app->forgetInstance(Recorder::class);
    $this->app->singleton(Recorder::class, fn (): Recorder => new Recorder(
        gate: new ConfigGate(enabled: true),
        allowlist: new FieldAllowlist(strictMode: true),
        rotator: new Rotator,
        writer: new JsonlWriter,
        activePath: $this->telemetryPath,
    ));
    $this->app->forgetInstance(GateRunner::class);
    $this->app->singleton(GateRunner::class, fn ($app): GateRunner => new GateRunner(
        recorder: $app->make(Recorder::class),
        workingDirectory: sys_get_temp_dir(),
    ));
});

afterEach(function (): void {
    if (isset($this->telemetryPath) && file_exists($this->telemetryPath)) {
        @unlink($this->telemetryPath);
    }
});

/**
 * @return list<string>
 */
function checkReadEventNames(string $path): array
{
    if (! file_exists($path)) {
        return [];
    }
    $names = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $raw) {
        $decoded = json_decode((string) $raw, true);
        if (is_array($decoded) && isset($decoded['event']) && is_string($decoded['event'])) {
            $names[] = $decoded['event'];
        }
    }

    return $names;
}

it('exits 0 when every enabled gate passes', function (): void {
    $this->artisan('codeguard:check', ['--context' => 'manual'])
        ->assertExitCode(0)
        ->expectsOutputToContain('All gates passed');
});

it('exits 1 when a gate fails (fail-fast by default)', function (): void {
    config()->set('codeguard.gates', [
        'pint' => ['enabled' => true, 'command' => 'exit 0', 'description' => 'pint'],
        'phpstan' => ['enabled' => true, 'command' => 'exit 1', 'description' => 'phpstan'],
        'deptrac' => ['enabled' => true, 'command' => 'exit 0', 'description' => 'deptrac'],
    ]);

    $this->artisan('codeguard:check')
        ->assertExitCode(1)
        ->expectsOutputToContain('Fail-fast')
        ->expectsOutputToContain('phpstan');
});

it('runs every gate when --all is passed, even after a failure', function (): void {
    config()->set('codeguard.gates', [
        'pint' => ['enabled' => true, 'command' => 'exit 1', 'description' => 'pint (fail)'],
        'phpstan' => ['enabled' => true, 'command' => 'exit 0', 'description' => 'phpstan'],
        'deptrac' => ['enabled' => true, 'command' => 'exit 0', 'description' => 'deptrac'],
    ]);

    $this->artisan('codeguard:check', ['--all' => true])
        ->assertExitCode(1);

    // Every gate.ended event should be present — not fail-fast'd.
    $events = checkReadEventNames($this->telemetryPath);
    $endedCount = count(array_filter($events, static fn (string $e): bool => $e === 'gate.ended'));
    expect($endedCount)->toBe(3);
});

it('emits command.start + command.end envelope plus paired gate events', function (): void {
    $this->artisan('codeguard:check', ['--context' => 'ci'])->assertExitCode(0);

    $events = checkReadEventNames($this->telemetryPath);

    expect($events[0])->toBe('command.start');
    expect(end($events))->toBe('command.end');

    // 3 gates × (started + ended) = 6 gate events between the envelopes.
    $gateEvents = array_values(array_filter(
        $events,
        static fn (string $e): bool => str_starts_with($e, 'gate.'),
    ));
    expect($gateEvents)->toHaveCount(6);
});

it('filters gates via --gate option and warns on unknown keys', function (): void {
    $this->artisan('codeguard:check', ['--gate' => ['pint', 'bogus']])
        ->assertExitCode(0)
        ->expectsOutputToContain('bogus')
        ->expectsOutputToContain('pint');

    // Only 1 gate should have run — pint.
    $events = checkReadEventNames($this->telemetryPath);
    $gateStarted = count(array_filter($events, static fn (string $e): bool => $e === 'gate.started'));
    expect($gateStarted)->toBe(1);
});

it('warns when no matching gates remain after filtering', function (): void {
    $this->artisan('codeguard:check', ['--gate' => ['nonexistent']])
        ->assertExitCode(0)
        ->expectsOutputToContain('No matching gates');
});

it('falls back to manual when --context is invalid, keeping telemetry valid', function (): void {
    // Invalid context should be normalised to 'manual' rather than leaking a
    // free-form string into the jsonl (FieldAllowlist would drop it anyway).
    $this->artisan('codeguard:check', ['--context' => 'something-weird'])->assertExitCode(0);

    $events = file($this->telemetryPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($events as $raw) {
        $decoded = json_decode((string) $raw, true);
        if (is_array($decoded) && ($decoded['event'] ?? '') === 'gate.started') {
            expect($decoded['context'])->toBe('manual');

            return;
        }
    }
});
