<?php

declare(strict_types=1);

use Henryavila\Codeguard\Analyze\AnalysisUnit;
use Henryavila\Codeguard\Analyze\Pattern;
use Henryavila\Codeguard\Analyze\PatternMatch;
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
    $match = PatternMatch::fromArray(pmtRaw(), pmtUnit(), []);

    expect($match)->toBeInstanceOf(PatternMatch::class)
        ->and($match?->patternKey)->toBe('no-god-object')
        ->and($match?->line)->toBe(12)
        ->and($match?->severity)->toBe(Severity::Critical)
        ->and($match?->file)->toBe('/work/app/Foo.php');
});

it('accepts a finding whose pattern is an allowed graph-level key (not dispatched per-file)', function (): void {
    // Graph-level patterns are dispatched at graph scope, so they are not in the
    // unit's per-file patternKeys(); they are admitted only via the explicit allowlist.
    $match = PatternMatch::fromArray(pmtRaw(['pattern_key' => 'no-circular-dependencies']), pmtUnit(), ['no-circular-dependencies']);

    expect($match)->toBeInstanceOf(PatternMatch::class)
        ->and($match?->patternKey)->toBe('no-circular-dependencies');
});

it('drops a finding for a pattern neither dispatched for the unit nor in the allowed graph-level keys', function (): void {
    // 'no-circular-dependencies' is a real corpus pattern, but it was NOT dispatched
    // for this unit and is NOT in the allowlist for this run — it must be rejected.
    expect(PatternMatch::fromArray(pmtRaw(['pattern_key' => 'no-circular-dependencies']), pmtUnit(), []))->toBeNull();
});

it('drops a finding with an unknown pattern key', function (): void {
    expect(PatternMatch::fromArray(pmtRaw(['pattern_key' => 'ghost']), pmtUnit(), []))->toBeNull();
});

it('drops a finding pointing at a different file (anti-hallucination)', function (): void {
    expect(PatternMatch::fromArray(pmtRaw(['file' => '/work/app/Other.php']), pmtUnit(), []))->toBeNull();
});

it('drops a finding with an invalid severity', function (): void {
    expect(PatternMatch::fromArray(pmtRaw(['severity' => 'blocker']), pmtUnit(), []))->toBeNull();
});

it('drops a finding with out-of-range confidence', function (): void {
    expect(PatternMatch::fromArray(pmtRaw(['confidence' => 1.5]), pmtUnit(), []))->toBeNull();
});

it('drops a finding with a non-numeric line', function (): void {
    expect(PatternMatch::fromArray(pmtRaw(['line' => 'abc']), pmtUnit(), []))->toBeNull();
});

it('parses a verified_score into the match', function (): void {
    $match = PatternMatch::fromArray(pmtRaw(['verified_score' => 7]), pmtUnit(), []);

    expect($match?->verifiedScore)->toBe(7);
});

it('keeps a verified_score of 0 on the match (the runner, not the trust boundary, drops it)', function (): void {
    $match = PatternMatch::fromArray(pmtRaw(['verified_score' => 0]), pmtUnit(), []);

    expect($match)->toBeInstanceOf(PatternMatch::class)
        ->and($match?->verifiedScore)->toBe(0);
});

it('treats an absent verified_score as uncritiqued (null)', function (): void {
    expect(PatternMatch::fromArray(pmtRaw(), pmtUnit(), [])?->verifiedScore)->toBeNull();
});

it('ignores an out-of-range verified_score (treats it as uncritiqued)', function (): void {
    expect(PatternMatch::fromArray(pmtRaw(['verified_score' => 15]), pmtUnit(), [])?->verifiedScore)->toBeNull()
        ->and(PatternMatch::fromArray(pmtRaw(['verified_score' => -3]), pmtUnit(), [])?->verifiedScore)->toBeNull();
});

it('parses a related_file (the other end of a bad dependency) onto the match', function (): void {
    $match = PatternMatch::fromArray(pmtRaw(['related_file' => 'App\\Http\\OrderController']), pmtUnit(), []);

    expect($match?->relatedFile)->toBe('App\\Http\\OrderController');
});

it('leaves related_file null when absent or not a string', function (): void {
    expect(PatternMatch::fromArray(pmtRaw(), pmtUnit(), [])?->relatedFile)->toBeNull()
        ->and(PatternMatch::fromArray(pmtRaw(['related_file' => 123]), pmtUnit(), [])?->relatedFile)->toBeNull();
});
