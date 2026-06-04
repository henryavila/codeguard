<?php

declare(strict_types=1);

use Henryavila\Codeguard\Analyze\PatternMatcher;
use Henryavila\Codeguard\Analyze\YamlPatternLoader;
use Illuminate\Filesystem\Filesystem;

/*
|--------------------------------------------------------------------------
| Pattern selection coverage
|--------------------------------------------------------------------------
|
| The honest, subscription-free half of the recall question: given the REAL
| corpus, does the matcher ATTACH the right pattern to the right file? This
| proves selection/scoping (deterministic). Whether the subagent then CATCHES
| the smell is a separate, manual recall ritual (see docs/patterns-recall.md).
|
*/

function pscPatternsPath(): string
{
    return dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'patterns';
}

function pscWrite(string $base, string $relative, string $contents): void
{
    $path = $base.DIRECTORY_SEPARATOR.$relative;
    $dir = dirname($path);
    if (! is_dir($dir)) {
        mkdir($dir, 0o755, true);
    }
    file_put_contents($path, $contents);
}

function pscCleanup(string $base): void
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

it('attaches the right patterns to the right files from the real corpus', function (): void {
    $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-psc-'.uniqid();
    pscWrite($base, 'app/Http/Controllers/OrderController.php', "<?php\nnamespace App\\Http\\Controllers;\nuse App\\Services\\OrderService;\nclass OrderController { public function store(): void {} }\n");
    pscWrite($base, 'app/Models/User.php', "<?php\nnamespace App\\Models;\nclass User {}\n");
    pscWrite($base, 'config/app.php', "<?php\nreturn ['name' => 'codeguard'];\n");
    pscWrite($base, 'resources/views/home.blade.php', "<div>{{ \$value }}</div>\n");

    try {
        $patterns = (new YamlPatternLoader(new Filesystem, pscPatternsPath()))
            ->forPresets(['core', 'php', 'php-laravel']);
        $matcher = new PatternMatcher($base);

        $units = $matcher->match([
            $base.'/app/Http/Controllers/OrderController.php',
            $base.'/app/Models/User.php',
            $base.'/config/app.php',
            $base.'/resources/views/home.blade.php',
        ], $patterns);

        $byFile = [];
        foreach ($units as $unit) {
            $byFile[basename($unit->file)] = $unit->patternKeys();
        }

        // Controller importing a service → service-layer is selected (real `use` parsing).
        expect($byFile['OrderController.php'] ?? [])->toContain('service-layer');

        // A model class → universal structure patterns, but NOT the service-layer
        // pattern (it neither lives in app/Services nor imports one).
        expect($byFile['User.php'] ?? [])->toContain('no-god-object')
            ->and(in_array('service-layer', $byFile['User.php'] ?? [], true))->toBeFalse();

        // A class-less config array → class-structure patterns are gated out.
        expect(in_array('no-god-object', $byFile['config/app.php'] ?? $byFile['app.php'] ?? [], true))->toBeFalse();

        // A Blade view → the blade-specific pattern is selected.
        expect($byFile['home.blade.php'] ?? [])->toContain('no-logic-in-blade');
    } finally {
        pscCleanup($base);
    }
});

it('attaches the high-impact contractor-dev patterns to a controller (R4 corpus)', function (): void {
    $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-psc-r4-'.uniqid();
    pscWrite($base, 'app/Http/Controllers/OrderController.php', "<?php\nnamespace App\\Http\\Controllers;\nclass OrderController { public function store(): void {} }\n");

    try {
        $patterns = (new YamlPatternLoader(new Filesystem, pscPatternsPath()))
            ->forPresets(['core', 'php', 'php-laravel']);

        $units = (new PatternMatcher($base))->match([$base.'/app/Http/Controllers/OrderController.php'], $patterns);
        $keys = $units[0]->patternKeys() ?? [];

        expect($keys)->toContain(
            'mass-assignment',
            'missing-authorization',
            'raw-sql-injection',
            'eloquent-n-plus-one',
            'unbounded-query',
            'missing-database-transaction',
        );
    } finally {
        pscCleanup($base);
    }
});
