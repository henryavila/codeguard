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

it('matches a file-glob signal and skips non-matching files', function (): void {
    $matcher = new PatternMatcher('/work');
    $pattern = pmPattern('p', [['type' => 'file', 'value' => '**/*.php']]);

    $units = $matcher->match(['/work/app/Foo.php', '/work/app/Bar.txt'], [$pattern]);

    expect($units)->toHaveCount(1)
        ->and($units[0]->file)->toBe('/work/app/Foo.php')
        ->and($units[0]->patternKeys())->toBe(['p']);
});

it('matches brace-expansion globs at the repo root too', function (): void {
    $matcher = new PatternMatcher('/work');
    $pattern = pmPattern('p', [['type' => 'file', 'value' => '**/*.{php,ts}']]);

    $units = $matcher->match(['/work/x/Foo.ts', '/work/Root.php', '/work/x/Foo.py'], [$pattern]);

    expect($units)->toHaveCount(2);
});

it('matches a directory signal by path prefix', function (): void {
    $matcher = new PatternMatcher('/work');
    $pattern = pmPattern('p', [['type' => 'directory', 'value' => 'app/Services']]);

    $units = $matcher->match(['/work/app/Services/OrderService.php', '/work/app/Models/User.php'], [$pattern]);

    expect($units)->toHaveCount(1)
        ->and($units[0]->file)->toBe('/work/app/Services/OrderService.php');
});

it('matches an import signal as a PSR-4 path prefix', function (): void {
    $matcher = new PatternMatcher('/work');
    $pattern = pmPattern('p', [['type' => 'import', 'value' => 'App\\Services\\*']]);

    $units = $matcher->match(['/work/app/Services/Foo.php', '/work/app/Http/Bar.php'], [$pattern]);

    expect($units)->toHaveCount(1)
        ->and($units[0]->file)->toBe('/work/app/Services/Foo.php');
});

it('produces no unit when nothing matches', function (): void {
    $matcher = new PatternMatcher('/work');
    $pattern = pmPattern('p', [['type' => 'directory', 'value' => 'app/Nope']]);

    expect($matcher->match(['/work/app/Foo.php'], [$pattern]))->toBe([]);
});
