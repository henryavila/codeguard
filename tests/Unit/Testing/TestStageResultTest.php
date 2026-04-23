<?php

declare(strict_types=1);

use Henryavila\Codeguard\Testing\TestStageResult;

function makeStageResult(
    int $exitCode = 0,
    ?int $passed = null,
    ?int $failed = null,
    ?int $skipped = null,
    array $failedTests = [],
    int $durationMs = 0,
): TestStageResult {
    return new TestStageResult(
        key: 'unit',
        label: 'Unit Tests',
        command: ['./vendor/bin/pest', '--testsuite=Unit'],
        exitCode: $exitCode,
        passed: $passed,
        failed: $failed,
        skipped: $skipped,
        failedTests: $failedTests,
        durationMs: $durationMs,
    );
}

it('reports PASS when exit 0, no failed count, no failedTests', function (): void {
    $r = makeStageResult();

    expect($r->hasFailures())->toBeFalse()
        ->and($r->status())->toBe('PASS');
});

it('reports FAIL when failed > 0', function (): void {
    $r = makeStageResult(exitCode: 0, failed: 3);

    expect($r->hasFailures())->toBeTrue()
        ->and($r->status())->toBe('FAIL');
});

it('reports FAIL when failed=0 but failedTests is non-empty (parser mismatch safeguard)', function (): void {
    $r = makeStageResult(exitCode: 0, failed: 0, failedTests: ['Tests\\Unit\\FooTest::it_does']);

    expect($r->hasFailures())->toBeTrue();
});

it('reports FAIL when exit is non-zero even without parsed counts', function (): void {
    $r = makeStageResult(exitCode: 2);

    expect($r->hasFailures())->toBeTrue();
});

it('exposes readonly primitives', function (): void {
    $r = makeStageResult(passed: 10, failed: 0, skipped: 1, durationMs: 1200);

    expect($r->key)->toBe('unit')
        ->and($r->label)->toBe('Unit Tests')
        ->and($r->passed)->toBe(10)
        ->and($r->skipped)->toBe(1)
        ->and($r->durationMs)->toBe(1200)
        ->and($r->command)->toBe(['./vendor/bin/pest', '--testsuite=Unit']);
});
