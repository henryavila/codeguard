<?php

declare(strict_types=1);

use Henryavila\Codeguard\Analyze\AnalyzeBaseline;
use Henryavila\Codeguard\Analyze\PatternMatch;
use Henryavila\Codeguard\Analyze\Severity;
use Illuminate\Filesystem\Filesystem;

function ablPath(): string
{
    return sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-abl-'.uniqid().'.json';
}

function ablMatch(string $key, string $file, int $line, string $message, Severity $severity): PatternMatch
{
    return new PatternMatch($key, $file, $line, $message, $severity, 0.9);
}

it('accepts a finding and recognizes it, including from a fresh instance on disk', function (): void {
    $path = ablPath();

    try {
        $baseline = new AnalyzeBaseline(new Filesystem, $path, '/work');
        $match = ablMatch('no-god-object', '/work/app/Foo.php', 5, 'too many methods', Severity::Critical);

        expect($baseline->isAccepted($match))->toBeFalse()
            ->and($baseline->accept([$match]))->toBe(1)
            ->and($baseline->isAccepted($match))->toBeTrue();

        // A fresh instance must read the persisted fingerprint.
        $reloaded = new AnalyzeBaseline(new Filesystem, $path, '/work');
        expect($reloaded->isAccepted($match))->toBeTrue();
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

it('fingerprints on pattern + relative file only — independent of message and line', function (): void {
    $path = ablPath();

    try {
        $baseline = new AnalyzeBaseline(new Filesystem, $path, '/work');
        $baseline->accept([ablMatch('no-god-object', '/work/app/Foo.php', 10, 'phrasing A', Severity::Warning)]);

        // Same pattern + file, different line + message + severity → still suppressed.
        $rephrased = ablMatch('no-god-object', '/work/app/Foo.php', 99, 'completely different phrasing', Severity::Critical);
        // Different pattern, same file → not suppressed.
        $otherPattern = ablMatch('dry', '/work/app/Foo.php', 10, 'phrasing A', Severity::Warning);
        // Same pattern, different file → not suppressed.
        $otherFile = ablMatch('no-god-object', '/work/app/Bar.php', 10, 'phrasing A', Severity::Warning);

        expect($baseline->isAccepted($rephrased))->toBeTrue()
            ->and($baseline->isAccepted($otherPattern))->toBeFalse()
            ->and($baseline->isAccepted($otherFile))->toBeFalse();
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

it('does not double-count an already-accepted finding', function (): void {
    $path = ablPath();

    try {
        $baseline = new AnalyzeBaseline(new Filesystem, $path, '/work');
        $match = ablMatch('dry', '/work/app/Foo.php', 1, 'm', Severity::Warning);

        expect($baseline->accept([$match]))->toBe(1)
            ->and($baseline->accept([$match]))->toBe(0);
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});
