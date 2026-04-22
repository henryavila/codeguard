<?php

declare(strict_types=1);

use CaptainHook\App\Config;
use CaptainHook\App\Console\IO;
use CaptainHook\App\Hook\Action;
use Henryavila\Codeguard\Telemetry\ConfigGate;
use Henryavila\Codeguard\Telemetry\FieldAllowlist;
use Henryavila\Codeguard\Telemetry\JsonlWriter;
use Henryavila\Codeguard\Telemetry\MeasuredAction;
use Henryavila\Codeguard\Telemetry\Recorder;
use Henryavila\Codeguard\Telemetry\Rotator;
use Mockery\MockInterface;
use SebastianFeldmann\Git\Repository;

function measuredTempDir(): string
{
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-measured-'.uniqid();
    mkdir($dir, 0o755, recursive: true);

    return $dir;
}

function measuredCleanup(string $dir): void
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

function measuredRecorder(string $path): Recorder
{
    return new Recorder(
        gate: new ConfigGate(enabled: true),
        allowlist: new FieldAllowlist(strictMode: true),
        rotator: new Rotator,
        writer: new JsonlWriter,
        activePath: $path,
    );
}

/**
 * @return array<int, array<string, mixed>>
 */
function measuredReadLines(string $path): array
{
    if (! file_exists($path)) {
        return [];
    }
    $lines = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $raw) {
        $decoded = json_decode((string) $raw, true);
        if (is_array($decoded)) {
            $lines[] = $decoded;
        }
    }

    return $lines;
}

afterEach(function (): void {
    Mockery::close();
});

it('emits gate.started before and gate.ended after a successful inner execute', function (): void {
    $dir = measuredTempDir();
    $path = $dir.DIRECTORY_SEPARATOR.'telemetry.jsonl';

    try {
        $recorder = measuredRecorder($path);

        /** @var Action&MockInterface $inner */
        $inner = Mockery::mock(Action::class);
        $inner->shouldReceive('execute')->once();

        $decorator = new MeasuredAction(
            inner: $inner,
            recorder: $recorder,
            gate: 'phpstan',
            context: 'pre-commit',
        );

        $decorator->execute(
            Mockery::mock(Config::class),
            Mockery::mock(IO::class),
            Mockery::mock(Repository::class),
            new Config\Action(action: MeasuredAction::class, options: []),
        );

        $events = array_map(static fn (array $l): array => [
            'event' => (string) ($l['event'] ?? ''),
            'status' => (string) ($l['status'] ?? ''),
            'gate' => (string) ($l['gate'] ?? ''),
            'context' => (string) ($l['context'] ?? ''),
        ], measuredReadLines($path));

        expect($events)->toBe([
            ['event' => 'gate.started', 'status' => 'ok', 'gate' => 'phpstan', 'context' => 'pre-commit'],
            ['event' => 'gate.ended', 'status' => 'ok', 'gate' => 'phpstan', 'context' => 'pre-commit'],
        ]);
    } finally {
        measuredCleanup($dir);
    }
});

it('emits gate.ended with fail status when inner execute throws, and rethrows', function (): void {
    $dir = measuredTempDir();
    $path = $dir.DIRECTORY_SEPARATOR.'telemetry.jsonl';

    try {
        $recorder = measuredRecorder($path);

        /** @var Action&MockInterface $inner */
        $inner = Mockery::mock(Action::class);
        $inner->shouldReceive('execute')
            ->once()
            ->andThrow(new RuntimeException('gate exploded'));

        $decorator = new MeasuredAction(
            inner: $inner,
            recorder: $recorder,
            gate: 'pint',
            context: 'manual',
        );

        expect(fn () => $decorator->execute(
            Mockery::mock(Config::class),
            Mockery::mock(IO::class),
            Mockery::mock(Repository::class),
            new Config\Action(action: MeasuredAction::class, options: []),
        ))->toThrow(RuntimeException::class, 'gate exploded');

        $lines = measuredReadLines($path);
        expect($lines)->toHaveCount(2)
            ->and($lines[0]['event'])->toBe('gate.started')
            ->and($lines[1]['event'])->toBe('gate.ended')
            ->and($lines[1]['status'])->toBe('fail');
    } finally {
        measuredCleanup($dir);
    }
});

it('records duration_ms greater than zero when the inner action takes real time', function (): void {
    $dir = measuredTempDir();
    $path = $dir.DIRECTORY_SEPARATOR.'telemetry.jsonl';

    try {
        $recorder = measuredRecorder($path);

        /** @var Action&MockInterface $inner */
        $inner = Mockery::mock(Action::class);
        $inner->shouldReceive('execute')
            ->once()
            ->andReturnUsing(static function (): void {
                usleep(5_000); // ~5 ms
            });

        $decorator = new MeasuredAction(
            inner: $inner,
            recorder: $recorder,
            gate: 'infection',
            context: 'ci',
        );

        $decorator->execute(
            Mockery::mock(Config::class),
            Mockery::mock(IO::class),
            Mockery::mock(Repository::class),
            new Config\Action(action: MeasuredAction::class, options: []),
        );

        $lines = measuredReadLines($path);
        expect($lines[1]['duration_ms'])->toBeGreaterThanOrEqual(1);
    } finally {
        measuredCleanup($dir);
    }
});
