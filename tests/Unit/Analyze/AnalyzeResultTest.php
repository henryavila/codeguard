<?php

declare(strict_types=1);

use Henryavila\Codeguard\Analyze\AnalyzeResult;
use Henryavila\Codeguard\Analyze\PatternMatch;
use Henryavila\Codeguard\Analyze\Severity;

function arMatch(Severity $severity): PatternMatch
{
    return new PatternMatch(
        patternKey: 'k',
        file: '/app/Foo.php',
        line: 1,
        message: 'm',
        severity: $severity,
        confidence: 0.9,
    );
}

it('passes when no finding reaches the fail-on threshold', function (): void {
    $result = new AnalyzeResult(2, [arMatch(Severity::Warning)], 10);

    expect($result->passed(Severity::Critical))->toBeTrue()
        ->and($result->failed(Severity::Critical))->toBeFalse();
});

it('fails when a finding meets the fail-on threshold', function (): void {
    $result = new AnalyzeResult(2, [arMatch(Severity::Warning)], 10);

    expect($result->passed(Severity::Warning))->toBeFalse()
        ->and($result->failed(Severity::Warning))->toBeTrue();
});

it('always passes when fail-on is never (null threshold)', function (): void {
    $result = new AnalyzeResult(2, [arMatch(Severity::Critical)], 10);

    expect($result->passed(null))->toBeTrue()
        ->and($result->matchesCount())->toBe(1);
});
