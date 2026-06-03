<?php

declare(strict_types=1);

use Henryavila\Codeguard\Telemetry\ConfigGate;
use Henryavila\Codeguard\Telemetry\EventName;
use Henryavila\Codeguard\Telemetry\FieldAllowlist;
use Henryavila\Codeguard\Telemetry\JsonlWriter;
use Henryavila\Codeguard\Telemetry\Recorder;
use Henryavila\Codeguard\Telemetry\Rotator;
use Henryavila\Codeguard\Telemetry\StopwatchScope;

function stopwatchTempDir(): string
{
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-stopwatch-'.uniqid();
    mkdir($dir, 0o755, recursive: true);

    return $dir;
}

function stopwatchCleanup(string $dir): void
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

function stopwatchMakeScope(string $path): StopwatchScope
{
    $recorder = new Recorder(
        gate: new ConfigGate(enabled: true),
        allowlist: new FieldAllowlist(strictMode: true),
        rotator: new Rotator,
        writer: new JsonlWriter,
        activePath: $path,
    );

    return new StopwatchScope($recorder);
}

/**
 * @return array<int, array<string, mixed>>
 */
function stopwatchReadLines(string $path): array
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

it('returns the callable value and records a single ended event with ok status', function (): void {
    $dir = stopwatchTempDir();
    $path = $dir.DIRECTORY_SEPARATOR.'telemetry.jsonl';

    try {
        $scope = stopwatchMakeScope($path);

        $result = $scope->measure(
            endEvent: EventName::AnalyzeEnded,
            extras: ['patterns_checked_count' => 28, 'matches_count' => 3],
            callable: static fn (): string => 'computed',
        );

        expect($result)->toBe('computed');

        $lines = stopwatchReadLines($path);
        expect($lines)->toHaveCount(1)
            ->and($lines[0]['event'])->toBe('analyze.ended')
            ->and($lines[0]['status'])->toBe('ok')
            ->and($lines[0]['patterns_checked_count'])->toBe(28)
            ->and($lines[0]['matches_count'])->toBe(3)
            ->and($lines[0]['duration_ms'])->toBeGreaterThanOrEqual(0);
    } finally {
        stopwatchCleanup($dir);
    }
});

it('records fail status and rethrows when the callable throws', function (): void {
    $dir = stopwatchTempDir();
    $path = $dir.DIRECTORY_SEPARATOR.'telemetry.jsonl';

    try {
        $scope = stopwatchMakeScope($path);

        $thrown = null;
        try {
            $scope->measure(
                endEvent: EventName::AnalyzeEnded,
                extras: ['patterns_checked_count' => 0, 'matches_count' => 0],
                callable: static fn () => throw new RuntimeException('boom'),
            );
        } catch (RuntimeException $e) {
            $thrown = $e;
        }

        expect($thrown)->toBeInstanceOf(RuntimeException::class)
            ->and($thrown->getMessage())->toBe('boom');

        $lines = stopwatchReadLines($path);
        expect($lines)->toHaveCount(1)
            ->and($lines[0]['status'])->toBe('fail');
    } finally {
        stopwatchCleanup($dir);
    }
});

it('records non-zero duration_ms for measurable work', function (): void {
    $dir = stopwatchTempDir();
    $path = $dir.DIRECTORY_SEPARATOR.'telemetry.jsonl';

    try {
        $scope = stopwatchMakeScope($path);

        $scope->measure(
            endEvent: EventName::AnalyzeEnded,
            extras: ['patterns_checked_count' => 0, 'matches_count' => 0],
            callable: static function (): void {
                usleep(5_000); // ~5 ms
            },
        );

        $lines = stopwatchReadLines($path);
        expect($lines[0]['duration_ms'])->toBeGreaterThanOrEqual(1);
    } finally {
        stopwatchCleanup($dir);
    }
});
