<?php

declare(strict_types=1);

use Henryavila\Codeguard\Analyze\AnalyzeOptions;

it('defaults to full corpus with critique floor 1 and excludes hygiene', function (): void {
    $opts = AnalyzeOptions::full();

    expect($opts->onlyPatternKeys)->toBeNull()
        ->and($opts->minCritiqueScore)->toBe(1)
        ->and($opts->excludeClassifications)->toBe(['hygiene'])
        ->and($opts->critiqueSurvives(null))->toBeTrue()
        ->and($opts->critiqueSurvives(0))->toBeFalse()
        ->and($opts->critiqueSurvives(1))->toBeTrue()
        ->and($opts->critiqueSurvives(3))->toBeTrue();
});

it('includeHygiene clears hygiene exclusion on full', function (): void {
    $opts = AnalyzeOptions::full(includeHygiene: true);

    expect($opts->excludeClassifications)->toBe([]);
});

it('contractor mode filters to G3 keys and raises critique floor to 4', function (): void {
    $opts = AnalyzeOptions::contractor();

    expect($opts->onlyPatternKeys)->toBe(AnalyzeOptions::CONTRACTOR_KEYS)
        ->and($opts->minCritiqueScore)->toBe(4)
        ->and($opts->critiqueSurvives(null))->toBeTrue()
        ->and($opts->critiqueSurvives(0))->toBeFalse()
        ->and($opts->critiqueSurvives(3))->toBeFalse()
        ->and($opts->critiqueSurvives(4))->toBeTrue()
        ->and($opts->onlyPatternKeys)->toContain('raw-sql-injection')
        ->and($opts->onlyPatternKeys)->toContain('service-layer')
        ->and($opts->onlyPatternKeys)->toContain('layer-dependency-direction');
});

it('resolve maps focus=contractor and optional score override', function (): void {
    $opts = AnalyzeOptions::resolve(focus: 'contractor', minCritiqueScore: 5);

    expect($opts->onlyPatternKeys)->toBe(AnalyzeOptions::CONTRACTOR_KEYS)
        ->and($opts->minCritiqueScore)->toBe(5);
});

it('resolve prefers explicit only-patterns over focus keys', function (): void {
    $opts = AnalyzeOptions::resolve(
        focus: 'full',
        onlyPatternKeys: ['raw-sql-injection', 'eloquent-n-plus-one'],
    );

    expect($opts->onlyPatternKeys)->toBe(['raw-sql-injection', 'eloquent-n-plus-one'])
        ->and($opts->minCritiqueScore)->toBe(1);
});

it('resolve accepts custom contractor_keys from config', function (): void {
    $opts = AnalyzeOptions::resolve(
        focus: 'contractor',
        contractorKeys: ['raw-sql-injection'],
    );

    expect($opts->onlyPatternKeys)->toBe(['raw-sql-injection']);
});

it('resolve full excludes hygiene unless includeHygiene', function (): void {
    expect(AnalyzeOptions::resolve(focus: 'full')->excludeClassifications)->toBe(['hygiene'])
        ->and(AnalyzeOptions::resolve(focus: 'full', includeHygiene: true)->excludeClassifications)->toBe([]);
});
