<?php

declare(strict_types=1);

use Henryavila\Codeguard\Analyze\FindingAction;
use Henryavila\Codeguard\Analyze\FindingActionClassifier;
use Henryavila\Codeguard\Analyze\PatternMatch;
use Henryavila\Codeguard\Analyze\Severity;

/**
 * @return PatternMatch
 */
function facMatch(
    string $key,
    Severity $severity = Severity::Critical,
    ?int $score = null,
): PatternMatch {
    return new PatternMatch(
        patternKey: $key,
        file: '/work/app/Foo.php',
        line: 1,
        message: 'test',
        severity: $severity,
        confidence: 0.9,
        verifiedScore: $score,
    );
}

it('classifies critical security patterns as block', function (string $key): void {
    $action = (new FindingActionClassifier)->classify(facMatch($key, Severity::Critical));

    expect($action)->toBe(FindingAction::Block);
})->with([
    'raw-sql-injection',
    'missing-authorization',
    'mass-assignment',
]);

it('does not block non-critical security pattern severity', function (): void {
    $action = (new FindingActionClassifier)->classify(
        facMatch('raw-sql-injection', Severity::Warning),
    );

    expect($action)->toBe(FindingAction::Info);
});

it('requests change for missing-database-transaction when uncritiqued or score>=4', function (): void {
    $c = new FindingActionClassifier;

    expect($c->classify(facMatch('missing-database-transaction', Severity::Warning, null)))
        ->toBe(FindingAction::RequestChange)
        ->and($c->classify(facMatch('missing-database-transaction', Severity::Warning, 4)))
        ->toBe(FindingAction::RequestChange)
        ->and($c->classify(facMatch('missing-database-transaction', Severity::Warning, 3)))
        ->toBe(FindingAction::Info);
});

it('requests change for N+1 and unbounded-query only when critique>=4', function (): void {
    $c = new FindingActionClassifier;

    expect($c->classify(facMatch('eloquent-n-plus-one', Severity::Warning, 4)))
        ->toBe(FindingAction::RequestChange)
        ->and($c->classify(facMatch('unbounded-query', Severity::Warning, 8)))
        ->toBe(FindingAction::RequestChange)
        ->and($c->classify(facMatch('eloquent-n-plus-one', Severity::Warning, 3)))
        ->toBe(FindingAction::Info)
        ->and($c->classify(facMatch('unbounded-query', Severity::Warning, null)))
        ->toBe(FindingAction::Info);
});

it('requests change for layer/service architecture patterns', function (string $key): void {
    $action = (new FindingActionClassifier)->classify(facMatch($key, Severity::Warning));

    expect($action)->toBe(FindingAction::RequestChange);
})->with([
    'service-layer',
    'layer-dependency-direction',
    'bounded-contexts',
    'no-circular-dependencies',
]);

it('defaults other patterns to info', function (): void {
    $action = (new FindingActionClassifier)->classify(
        facMatch('type-declarations', Severity::Suggestion, 9),
    );

    expect($action)->toBe(FindingAction::Info);
});
