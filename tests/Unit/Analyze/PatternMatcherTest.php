<?php

declare(strict_types=1);

use Henryavila\Codeguard\Analyze\Pattern;
use Henryavila\Codeguard\Analyze\PatternMatcher;

/**
 * @param  list<array{type: string, value: string}>  $signals
 */
function pmPattern(string $key, array $signals): Pattern
{
    return Pattern::fromArray($key, [
        'detection' => ['signals' => $signals],
        'verification' => ['rules' => ['r']],
        'examples' => ['correct' => '', 'violation' => ''],
        'severity' => 'warning',
    ]);
}

function pmBase(): string
{
    return sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-pm-'.uniqid();
}

function pmWriteFile(string $base, string $relative, string $contents): void
{
    $path = $base.DIRECTORY_SEPARATOR.$relative;
    $dir = dirname($path);
    if (! is_dir($dir)) {
        mkdir($dir, 0o755, true);
    }
    file_put_contents($path, $contents);
}

function pmCleanup(string $base): void
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

// ── path-based signals (no file read needed) ───────────────────────

it('matches a file-glob signal and skips non-matching files', function (): void {
    $matcher = new PatternMatcher('/work');
    $pattern = pmPattern('dry', [['type' => 'file', 'value' => '**/*.php']]);

    $units = $matcher->match(['/work/app/Foo.php', '/work/app/Bar.txt'], [$pattern]);

    expect($units)->toHaveCount(1)
        ->and($units[0]->file)->toBe('/work/app/Foo.php')
        ->and($units[0]->patternKeys())->toBe(['dry']);
});

it('matches brace-expansion globs at the repo root too', function (): void {
    $matcher = new PatternMatcher('/work');
    $pattern = pmPattern('dry', [['type' => 'file', 'value' => '**/*.{php,ts}']]);

    $units = $matcher->match(['/work/x/Foo.ts', '/work/Root.php', '/work/x/Foo.py'], [$pattern]);

    expect($units)->toHaveCount(2);
});

it('matches a directory signal by path prefix', function (): void {
    $matcher = new PatternMatcher('/work');
    $pattern = pmPattern('dry', [['type' => 'directory', 'value' => 'app/Services']]);

    $units = $matcher->match(['/work/app/Services/OrderService.php', '/work/app/Models/User.php'], [$pattern]);

    expect($units)->toHaveCount(1)
        ->and($units[0]->file)->toBe('/work/app/Services/OrderService.php');
});

it('produces no unit when nothing matches', function (): void {
    $matcher = new PatternMatcher('/work');
    $pattern = pmPattern('dry', [['type' => 'directory', 'value' => 'app/Nope']]);

    expect($matcher->match(['/work/app/Foo.php'], [$pattern]))->toBe([]);
});

// ── import signals (real `use` parsing) + guards ───────────────────

it('matches an import signal against the file actual use statements', function (): void {
    $base = pmBase();

    try {
        pmWriteFile($base, 'app/Http/OrderController.php', "<?php\nnamespace App\\Http;\nuse App\\Services\\OrderService;\nclass OrderController {}\n");
        pmWriteFile($base, 'app/Http/PlainController.php', "<?php\nnamespace App\\Http;\nclass PlainController {}\n");

        $matcher = new PatternMatcher($base);
        $pattern = pmPattern('service-layer', [['type' => 'import', 'value' => 'App\\Services\\*']]);
        $files = [$base.'/app/Http/OrderController.php', $base.'/app/Http/PlainController.php'];

        $units = $matcher->match($files, [$pattern]);

        expect($units)->toHaveCount(1)
            ->and(basename($units[0]->file))->toBe('OrderController.php');
    } finally {
        pmCleanup($base);
    }
});

it('gates class-structure patterns to files that declare a class', function (): void {
    $base = pmBase();

    try {
        pmWriteFile($base, 'app/Foo.php', "<?php\nclass Foo { public function a() {} }\n");
        pmWriteFile($base, 'config/app.php', "<?php\nreturn ['x' => 1];\n");

        $matcher = new PatternMatcher($base);
        $pattern = pmPattern('no-god-object', [['type' => 'file', 'value' => '**/*.php']]);
        $files = [$base.'/app/Foo.php', $base.'/config/app.php'];

        $units = $matcher->match($files, [$pattern]);

        expect($units)->toHaveCount(1)
            ->and(basename($units[0]->file))->toBe('Foo.php');
    } finally {
        pmCleanup($base);
    }
});

it('does not select architectural patterns whose only signal is the catch-all import', function (): void {
    $base = pmBase();

    try {
        pmWriteFile($base, 'app/Foo.php', "<?php\nnamespace App;\nuse App\\Bar;\nclass Foo {}\n");

        $matcher = new PatternMatcher($base);
        $pattern = pmPattern('layer-dependency-direction', [['type' => 'import', 'value' => '**/*']]);

        expect($matcher->match([$base.'/app/Foo.php'], [$pattern]))->toBe([]);
    } finally {
        pmCleanup($base);
    }
});
