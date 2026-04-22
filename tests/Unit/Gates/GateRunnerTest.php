<?php

declare(strict_types=1);

use Henryavila\Codeguard\Gates\GateRunner;
use Henryavila\Codeguard\Telemetry\ConfigGate;
use Henryavila\Codeguard\Telemetry\FieldAllowlist;
use Henryavila\Codeguard\Telemetry\JsonlWriter;
use Henryavila\Codeguard\Telemetry\Recorder;
use Henryavila\Codeguard\Telemetry\Rotator;
use Henryavila\Codeguard\Testing\GateConfig;

function gateRunnerTempPath(): string
{
    return sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-gate-runner-'.uniqid().'.jsonl';
}

function gateRunnerMake(string $telemetryPath, bool $telemetryEnabled = true): GateRunner
{
    $recorder = new Recorder(
        gate: new ConfigGate(enabled: $telemetryEnabled),
        allowlist: new FieldAllowlist(strictMode: true),
        rotator: new Rotator,
        writer: new JsonlWriter,
        activePath: $telemetryPath,
    );

    return new GateRunner(recorder: $recorder, workingDirectory: sys_get_temp_dir());
}

/**
 * @return array<int, array<string, mixed>>
 */
function gateRunnerReadEvents(string $path): array
{
    if (! file_exists($path)) {
        return [];
    }
    $events = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $raw) {
        $decoded = json_decode((string) $raw, true);
        if (is_array($decoded)) {
            $events[] = $decoded;
        }
    }

    return $events;
}

it('runs a passing gate and returns exit code 0', function (): void {
    $path = gateRunnerTempPath();
    $runner = gateRunnerMake($path);

    $gate = new GateConfig(key: 'pint', enabled: true, command: 'exit 0', description: 'ok');

    $result = $runner->run($gate, 'manual');

    expect($result->gateKey)->toBe('pint')
        ->and($result->exitCode)->toBe(0)
        ->and($result->passed())->toBeTrue()
        ->and($result->durationMs)->toBeGreaterThanOrEqual(0);

    @unlink($path);
});

it('captures non-zero exit code for failing gate', function (): void {
    $path = gateRunnerTempPath();
    $runner = gateRunnerMake($path);

    $gate = new GateConfig(key: 'phpstan', enabled: true, command: 'exit 7', description: 'fails');

    $result = $runner->run($gate, 'ci');

    expect($result->exitCode)->toBe(7)
        ->and($result->failed())->toBeTrue();

    @unlink($path);
});

it('emits gate.started and gate.ended around the subprocess', function (): void {
    $path = gateRunnerTempPath();
    $runner = gateRunnerMake($path);

    $gate = new GateConfig(key: 'deptrac', enabled: true, command: 'exit 0', description: 'arch');
    $runner->run($gate, 'pre-commit');

    $events = gateRunnerReadEvents($path);
    expect($events)->toHaveCount(2)
        ->and($events[0]['event'])->toBe('gate.started')
        ->and($events[0]['gate'])->toBe('deptrac')
        ->and($events[0]['context'])->toBe('pre-commit')
        ->and($events[1]['event'])->toBe('gate.ended')
        ->and($events[1]['status'])->toBe('ok')
        ->and($events[1]['duration_ms'])->toBeGreaterThanOrEqual(0);

    @unlink($path);
});

it('emits gate.ended with status=fail when subprocess returns non-zero', function (): void {
    $path = gateRunnerTempPath();
    $runner = gateRunnerMake($path);

    $gate = new GateConfig(key: 'infection', enabled: true, command: 'exit 1', description: 'mut');
    $runner->run($gate, 'manual');

    $events = gateRunnerReadEvents($path);
    expect($events[1]['event'])->toBe('gate.ended')
        ->and($events[1]['status'])->toBe('fail');

    @unlink($path);
});

it('streams stdout to the provided sink closure', function (): void {
    $path = gateRunnerTempPath();
    $runner = gateRunnerMake($path);

    $captured = '';
    $gate = new GateConfig(key: 'pint', enabled: true, command: 'printf hello', description: 'x');
    $runner->run($gate, 'manual', function (string $_type, string $buffer) use (&$captured): void {
        $captured .= $buffer;
    });

    expect($captured)->toContain('hello');

    @unlink($path);
});

it('records no telemetry when the gate config is disabled', function (): void {
    $path = gateRunnerTempPath();
    $runner = gateRunnerMake($path, telemetryEnabled: false);

    $gate = new GateConfig(key: 'pint', enabled: true, command: 'exit 0', description: 'x');
    $runner->run($gate, 'manual');

    expect(file_exists($path))->toBeFalse();
});
