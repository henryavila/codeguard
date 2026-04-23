<?php

declare(strict_types=1);

use Henryavila\Codeguard\Telemetry\ConfigGate;
use Henryavila\Codeguard\Telemetry\FieldAllowlist;
use Henryavila\Codeguard\Telemetry\JsonlWriter;
use Henryavila\Codeguard\Telemetry\Recorder;
use Henryavila\Codeguard\Telemetry\Rotator;
use Henryavila\Codeguard\Testing\AsyncCommandExecutor;
use Henryavila\Codeguard\Testing\CodeguardConfig;
use Henryavila\Codeguard\Testing\CommandExecutor;
use Henryavila\Codeguard\Testing\ProcessCommandExecutor;
use Henryavila\Codeguard\Tests\Support\FakeCommandExecutor;

beforeEach(function (): void {
    config()->set('codeguard.stages', [
        'unit' => [
            'enabled' => true,
            'label' => 'Unit',
            'phase' => 1,
            'command' => ['echo', 'unit-ok'],
            'env' => [],
            'fast_fail_arguments' => [],
        ],
        'feature' => [
            'enabled' => true,
            'label' => 'Feature',
            'phase' => 2,
            'command' => ['echo', 'feature-ok'],
            'env' => [],
            'fast_fail_arguments' => [],
        ],
    ]);

    config()->set('codeguard.report_dir', sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-cmd-test-'.uniqid());

    $this->telemetryPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-test-cmd-'.uniqid().'.jsonl';
    $this->app->forgetInstance(Recorder::class);
    $this->app->singleton(Recorder::class, fn (): Recorder => new Recorder(
        gate: new ConfigGate(enabled: true),
        allowlist: new FieldAllowlist(strictMode: true),
        rotator: new Rotator,
        writer: new JsonlWriter,
        activePath: $this->telemetryPath,
    ));

    // Reset the codeguard config DTO so it reads the updated config()->set above.
    $this->app->forgetInstance(CodeguardConfig::class);
});

afterEach(function (): void {
    if (isset($this->telemetryPath) && file_exists($this->telemetryPath)) {
        @unlink($this->telemetryPath);
    }
});

/**
 * Swap the AsyncCommandExecutor binding with a fake driven by $handler.
 */
function bindFakeExecutor(Closure $handler): FakeCommandExecutor
{
    $fake = new FakeCommandExecutor($handler);

    // The service provider aliases ProcessCommandExecutor → CommandExecutor +
    // AsyncCommandExecutor, so rebind all three to the fake for full coverage.
    app()->forgetInstance(ProcessCommandExecutor::class);
    app()->instance(ProcessCommandExecutor::class, $fake);
    app()->instance(CommandExecutor::class, $fake);
    app()->instance(AsyncCommandExecutor::class, $fake);

    return $fake;
}

/**
 * @return list<string>
 */
function readTestCmdEventNames(string $path): array
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

it('exits 0 when every stage passes', function (): void {
    bindFakeExecutor(fn () => [0, '']);

    $this->artisan('codeguard:test')
        ->assertExitCode(0)
        ->expectsOutputToContain('Running 2 stage(s)');
});

it('exits 1 when a stage fails in fast-fail mode and skips later phases', function (): void {
    $fake = bindFakeExecutor(fn (array $cmd) => str_contains($cmd[1] ?? '', 'unit') ? [1, 'broke'] : [0, '']);

    $this->artisan('codeguard:test', ['--mode' => 'fast-fail'])
        ->assertExitCode(1);

    // Feature stage is phase 2 — must not have been executed
    expect($fake->executedCommands)->toHaveCount(1)
        ->and($fake->executedCommands[0])->toBe(['echo', 'unit-ok']);
});

it('runs every stage in report mode even after a failure', function (): void {
    $fake = bindFakeExecutor(fn (array $cmd) => str_contains($cmd[1] ?? '', 'unit') ? [1, ''] : [0, '']);

    $this->artisan('codeguard:test', ['--mode' => 'report'])->assertExitCode(1);

    expect($fake->executedCommands)->toHaveCount(2);
});

it('honors --stage filter and only runs the matching stage', function (): void {
    $fake = bindFakeExecutor(fn () => [0, '']);

    $this->artisan('codeguard:test', ['--stage' => 'feature'])->assertExitCode(0);

    expect($fake->executedCommands)->toHaveCount(1)
        ->and($fake->executedCommands[0])->toBe(['echo', 'feature-ok']);
});

it('warns when --stage key does not match any enabled stage', function (): void {
    bindFakeExecutor(fn () => [0, '']);

    $this->artisan('codeguard:test', ['--stage' => 'nonexistent'])
        ->assertExitCode(0)
        ->expectsOutputToContain('nonexistent');
});

it('emits command.start + test.started + test.ended + command.end envelope', function (): void {
    bindFakeExecutor(fn () => [0, '']);

    $this->artisan('codeguard:test', ['--context' => 'ci'])->assertExitCode(0);

    $events = readTestCmdEventNames($this->telemetryPath);

    expect($events[0])->toBe('command.start')
        ->and(end($events))->toBe('command.end')
        ->and($events)->toContain('test.started')
        ->and($events)->toContain('test.ended');
});

it('records failure status on test.ended when stages fail', function (): void {
    bindFakeExecutor(fn (array $cmd) => str_contains($cmd[1] ?? '', 'unit') ? [1, ''] : [0, '']);

    $this->artisan('codeguard:test', ['--mode' => 'report'])->assertExitCode(1);

    $lines = file($this->telemetryPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

    $testEnded = null;
    foreach ($lines as $line) {
        $decoded = json_decode((string) $line, true);
        if (is_array($decoded) && ($decoded['event'] ?? null) === 'test.ended') {
            $testEnded = $decoded;
            break;
        }
    }

    expect($testEnded)->not->toBeNull()
        ->and($testEnded['status'])->toBe('fail');
});

it('defaults coverage to on and flips with --no-coverage', function (): void {
    bindFakeExecutor(fn () => [0, '']);

    $this->artisan('codeguard:test', ['--no-coverage' => true])->assertExitCode(0);

    // Find the test.started event and assert with_coverage=false
    $lines = file($this->telemetryPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

    $testStarted = null;
    foreach ($lines as $line) {
        $decoded = json_decode((string) $line, true);
        if (is_array($decoded) && ($decoded['event'] ?? null) === 'test.started') {
            $testStarted = $decoded;
            break;
        }
    }

    expect($testStarted)->not->toBeNull()
        ->and($testStarted['with_coverage'])->toBeFalse();
});
