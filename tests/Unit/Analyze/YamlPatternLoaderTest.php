<?php

declare(strict_types=1);

use Henryavila\Codeguard\Analyze\YamlPatternLoader;
use Illuminate\Filesystem\Filesystem;

function aplBase(): string
{
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-apl-'.uniqid();
    mkdir($dir.'/core', 0o755, true);
    mkdir($dir.'/php', 0o755, true);

    return $dir;
}

function aplValidPattern(string $name, string $layer): string
{
    return "name: {$name}\n"
        ."description: desc for {$name}\n"
        ."category: solid\n"
        ."layer: {$layer}\n"
        ."severity: warning\n"
        ."classification: mvp\n"
        ."detection:\n"
        ."  signals:\n"
        ."    - type: file\n"
        ."      value: \"**/*.php\"\n"
        ."  confidence: high\n"
        ."verification:\n"
        ."  rules:\n"
        ."    - some rule\n"
        ."examples:\n"
        ."  correct: |\n"
        ."    ok\n"
        ."  violation: |\n"
        ."    bad\n";
}

function aplWrite(string $base, string $relative, string $contents): void
{
    $path = $base.DIRECTORY_SEPARATOR.$relative;
    $dir = dirname($path);
    if (! is_dir($dir)) {
        mkdir($dir, 0o755, true);
    }
    file_put_contents($path, $contents);
}

function aplCleanup(string $base): void
{
    if (! is_dir($base)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($base);
}

it('loads real patterns, skips outliers, and filters by preset', function (): void {
    $base = aplBase();

    try {
        aplWrite($base, 'core/no-god-object.yaml', aplValidPattern('no-god-object', 'core'));
        aplWrite($base, 'core/preset.yaml', "name: php-laravel\ntools:\n  - pint\n");
        aplWrite($base, 'php/strict-typing.yaml', aplValidPattern('strict-typing', 'php'));

        $loader = new YamlPatternLoader(new Filesystem, $base);

        expect($loader->forPresets(['core']))->toHaveCount(1)
            ->and($loader->forPresets(['core', 'php']))->toHaveCount(2)
            ->and($loader->has('no-god-object'))->toBeTrue()
            ->and($loader->has('strict-typing'))->toBeTrue()
            ->and($loader->has('preset'))->toBeFalse()
            ->and($loader->has('ghost'))->toBeFalse();
    } finally {
        aplCleanup($base);
    }
});

it('returns nothing for a missing preset directory', function (): void {
    $base = aplBase();

    try {
        $loader = new YamlPatternLoader(new Filesystem, $base);

        expect($loader->forPresets(['php-laravel']))->toBe([]);
    } finally {
        aplCleanup($base);
    }
});
