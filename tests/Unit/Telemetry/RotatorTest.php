<?php

declare(strict_types=1);

use Henryavila\Codeguard\Telemetry\Rotator;

function rotatorTempDir(): string
{
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-rotator-'.uniqid();
    mkdir($dir, 0o755, recursive: true);

    return $dir;
}

function rotatorCleanup(string $dir): void
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

it('does nothing when active file does not exist', function (): void {
    $dir = rotatorTempDir();
    $active = $dir.DIRECTORY_SEPARATOR.'telemetry.jsonl';

    try {
        $rotator = new Rotator(maxBytes: 10, retain: 5);
        $rotator->rotateIfNeeded($active);

        expect(file_exists($active))->toBeFalse()
            ->and(glob($dir.DIRECTORY_SEPARATOR.'*'))->toBe([]);
    } finally {
        rotatorCleanup($dir);
    }
});

it('does nothing when active file is under the size threshold', function (): void {
    $dir = rotatorTempDir();
    $active = $dir.DIRECTORY_SEPARATOR.'telemetry.jsonl';

    try {
        file_put_contents($active, str_repeat('x', 50));
        $rotator = new Rotator(maxBytes: 100, retain: 5);
        $rotator->rotateIfNeeded($active);

        expect(file_exists($active))->toBeTrue()
            ->and(filesize($active))->toBe(50);
    } finally {
        rotatorCleanup($dir);
    }
});

it('renames active file to timestamped archive when threshold exceeded', function (): void {
    $dir = rotatorTempDir();
    $active = $dir.DIRECTORY_SEPARATOR.'telemetry.jsonl';

    try {
        file_put_contents($active, str_repeat('x', 200));
        $clock = static fn (): DateTimeImmutable => new DateTimeImmutable('2026-04-22 10:30:00');
        $rotator = new Rotator(maxBytes: 100, retain: 5, clock: $clock);
        $rotator->rotateIfNeeded($active);

        expect(file_exists($active))->toBeFalse();
        $archive = $dir.DIRECTORY_SEPARATOR.'telemetry-2026-04-22-103000.jsonl';
        expect(file_exists($archive))->toBeTrue()
            ->and(filesize($archive))->toBe(200);
    } finally {
        rotatorCleanup($dir);
    }
});

it('prunes old archives keeping only retain most recent', function (): void {
    $dir = rotatorTempDir();
    $active = $dir.DIRECTORY_SEPARATOR.'telemetry.jsonl';

    try {
        // Build 7 archive files with increasing mtimes.
        $now = time();
        foreach (range(1, 7) as $i) {
            $archive = $dir.DIRECTORY_SEPARATOR."telemetry-archive-{$i}.jsonl";
            file_put_contents($archive, "line {$i}");
            touch($archive, $now - (7 - $i) * 60); // oldest = 1, newest = 7
        }

        // Trigger rotation so prune runs.
        file_put_contents($active, str_repeat('x', 200));
        $clock = static fn (): DateTimeImmutable => new DateTimeImmutable('2026-04-22 10:30:00');
        $rotator = new Rotator(maxBytes: 100, retain: 3, clock: $clock);
        $rotator->rotateIfNeeded($active);

        $remaining = glob($dir.DIRECTORY_SEPARATOR.'telemetry-*.jsonl') ?: [];

        // Keep top 3 by mtime: the newly-rotated active (newest), plus the 2
        // most recent of the pre-existing archives (6 and 7).
        expect($remaining)->toHaveCount(3);

        // The oldest five (archives 1..5) must be gone.
        foreach (range(1, 5) as $i) {
            expect(file_exists($dir.DIRECTORY_SEPARATOR."telemetry-archive-{$i}.jsonl"))
                ->toBeFalse("archive-{$i} should have been pruned");
        }
    } finally {
        rotatorCleanup($dir);
    }
});

it('avoids collision when two rotations land in the same second', function (): void {
    $dir = rotatorTempDir();
    $active = $dir.DIRECTORY_SEPARATOR.'telemetry.jsonl';

    try {
        $clock = static fn (): DateTimeImmutable => new DateTimeImmutable('2026-04-22 10:30:00');
        $rotator = new Rotator(maxBytes: 5, retain: 10, clock: $clock);

        file_put_contents($active, 'first_rotation_payload');
        $rotator->rotateIfNeeded($active);

        file_put_contents($active, 'second_rotation_payload');
        $rotator->rotateIfNeeded($active);

        $archives = glob($dir.DIRECTORY_SEPARATOR.'telemetry-*.jsonl') ?: [];
        expect($archives)->toHaveCount(2);
    } finally {
        rotatorCleanup($dir);
    }
});
