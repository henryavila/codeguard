<?php

declare(strict_types=1);

use Henryavila\Codeguard\Analyze\AnalysisUnit;
use Henryavila\Codeguard\Analyze\Pattern;
use Henryavila\Codeguard\Analyze\PatternMatch;
use Henryavila\Codeguard\Analyze\PatternRepository;
use Henryavila\Codeguard\Analyze\Severity;

function pmtUnit(): AnalysisUnit
{
    $pattern = Pattern::fromArray('no-god-object', [
        'detection' => ['signals' => [['type' => 'file', 'value' => '**/*.php']]],
        'verification' => ['rules' => ['r']],
        'examples' => ['correct' => '', 'violation' => ''],
        'severity' => 'critical',
    ]);

    return new AnalysisUnit('/work/app/Foo.php', [$pattern]);
}

function pmtRepo(): PatternRepository
{
    return new class implements PatternRepository
    {
        /**
         * @param  list<string>  $presets
         * @return list<Pattern>
         */
        public function forPresets(array $presets): array
        {
            return [];
        }

        public function has(string $key): bool
        {
            return $key === 'extra-known';
        }
    };
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pmtRaw(array $overrides = []): array
{
    return array_merge([
        'pattern_key' => 'no-god-object',
        'file' => '/work/app/Foo.php',
        'line' => 12,
        'message' => 'too many public methods',
        'severity' => 'critical',
        'confidence' => 0.9,
    ], $overrides);
}

it('accepts a well-formed finding for a dispatched pattern', function (): void {
    $match = PatternMatch::fromArray(pmtRaw(), pmtUnit(), pmtRepo());

    expect($match)->toBeInstanceOf(PatternMatch::class)
        ->and($match?->patternKey)->toBe('no-god-object')
        ->and($match?->line)->toBe(12)
        ->and($match?->severity)->toBe(Severity::Critical)
        ->and($match?->file)->toBe('/work/app/Foo.php');
});

it('accepts a finding whose pattern is known to the repository', function (): void {
    $match = PatternMatch::fromArray(pmtRaw(['pattern_key' => 'extra-known']), pmtUnit(), pmtRepo());

    expect($match)->toBeInstanceOf(PatternMatch::class);
});

it('drops a finding with an unknown pattern key', function (): void {
    expect(PatternMatch::fromArray(pmtRaw(['pattern_key' => 'ghost']), pmtUnit(), pmtRepo()))->toBeNull();
});

it('drops a finding pointing at a different file (anti-hallucination)', function (): void {
    expect(PatternMatch::fromArray(pmtRaw(['file' => '/work/app/Other.php']), pmtUnit(), pmtRepo()))->toBeNull();
});

it('drops a finding with an invalid severity', function (): void {
    expect(PatternMatch::fromArray(pmtRaw(['severity' => 'blocker']), pmtUnit(), pmtRepo()))->toBeNull();
});

it('drops a finding with out-of-range confidence', function (): void {
    expect(PatternMatch::fromArray(pmtRaw(['confidence' => 1.5]), pmtUnit(), pmtRepo()))->toBeNull();
});

it('drops a finding with a non-numeric line', function (): void {
    expect(PatternMatch::fromArray(pmtRaw(['line' => 'abc']), pmtUnit(), pmtRepo()))->toBeNull();
});
