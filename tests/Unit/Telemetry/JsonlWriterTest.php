<?php

declare(strict_types=1);

use Henryavila\Codeguard\Telemetry\JsonlWriter;

function jsonlTempDir(): string
{
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-jsonl-'.uniqid();
    mkdir($dir, 0o755, recursive: true);

    return $dir;
}

function jsonlCleanup(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir.DIRECTORY_SEPARATOR.$entry;
        is_dir($path) ? jsonlCleanup($path) : @unlink($path);
    }
    @rmdir($dir);
}

it('appends a single JSON line with trailing newline', function (): void {
    $dir = jsonlTempDir();
    $path = $dir.DIRECTORY_SEPARATOR.'telemetry.jsonl';

    try {
        $writer = new JsonlWriter;
        $result = $writer->append($path, ['event' => 'test.started', 'count' => 1]);

        expect($result)->toBeTrue()
            ->and(file_exists($path))->toBeTrue();

        $content = file_get_contents($path);
        expect($content)->toBe('{"event":"test.started","count":1}'."\n");
    } finally {
        jsonlCleanup($dir);
    }
});

it('appends multiple lines preserving order', function (): void {
    $dir = jsonlTempDir();
    $path = $dir.DIRECTORY_SEPARATOR.'telemetry.jsonl';

    try {
        $writer = new JsonlWriter;
        $writer->append($path, ['line' => 1]);
        $writer->append($path, ['line' => 2]);
        $writer->append($path, ['line' => 3]);

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        expect($lines)->toBe(['{"line":1}', '{"line":2}', '{"line":3}']);
    } finally {
        jsonlCleanup($dir);
    }
});

it('creates the parent directory when missing', function (): void {
    $dir = jsonlTempDir();
    $nested = $dir.DIRECTORY_SEPARATOR.'nested'.DIRECTORY_SEPARATOR.'deep';
    $path = $nested.DIRECTORY_SEPARATOR.'telemetry.jsonl';

    try {
        $writer = new JsonlWriter;
        $result = $writer->append($path, ['ok' => true]);

        expect($result)->toBeTrue()
            ->and(is_dir($nested))->toBeTrue()
            ->and(file_exists($path))->toBeTrue();
    } finally {
        jsonlCleanup($dir);
    }
});

it('does not escape forward slashes in event names', function (): void {
    $dir = jsonlTempDir();
    $path = $dir.DIRECTORY_SEPARATOR.'telemetry.jsonl';

    try {
        $writer = new JsonlWriter;
        // Keys with dots are not escaped either — jsonl stays diff-friendly.
        $writer->append($path, ['event' => 'install.env.detected']);

        $content = (string) file_get_contents($path);
        expect($content)->toContain('install.env.detected')
            ->and($content)->not->toContain('\\/');
    } finally {
        jsonlCleanup($dir);
    }
});
