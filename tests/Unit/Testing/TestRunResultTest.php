<?php

declare(strict_types=1);

use Henryavila\Codeguard\Testing\TestRunResult;
use Henryavila\Codeguard\Testing\TestStageResult;

function stageWith(string $key, bool $fail = false, int $duration = 100): TestStageResult
{
    return new TestStageResult(
        key: $key,
        label: ucfirst($key),
        command: ['echo', $key],
        exitCode: $fail ? 1 : 0,
        passed: $fail ? 0 : 5,
        failed: $fail ? 1 : 0,
        skipped: 0,
        failedTests: [],
        durationMs: $duration,
    );
}

it('aggregates hasFailures across stages', function (): void {
    $all_pass = new TestRunResult([stageWith('unit'), stageWith('feature')]);
    $one_fail = new TestRunResult([stageWith('unit'), stageWith('feature', fail: true)]);

    expect($all_pass->hasFailures())->toBeFalse()
        ->and($one_fail->hasFailures())->toBeTrue();
});

it('finds stage by key or returns null', function (): void {
    $r = new TestRunResult([stageWith('unit'), stageWith('feature')]);

    expect($r->stage('unit'))->not->toBeNull()
        ->and($r->stage('unit')->key)->toBe('unit')
        ->and($r->stage('missing'))->toBeNull();
});

it('sums durations from stages when no explicit duration given', function (): void {
    $r = new TestRunResult([
        stageWith('a', duration: 100),
        stageWith('b', duration: 250),
        stageWith('c', duration: 400),
    ]);

    expect($r->durationMs())->toBe(750);
});

it('prefers explicit duration over stage sum when provided', function (): void {
    $r = new TestRunResult(
        stages: [stageWith('a', duration: 100), stageWith('b', duration: 250)],
        durationMs: 1000,
    );

    expect($r->durationMs())->toBe(1000);
});

it('returns logFilePath when given, null otherwise', function (): void {
    $no_log = new TestRunResult([stageWith('a')]);
    $with_log = new TestRunResult([stageWith('a')], logFilePath: '/tmp/run.log');

    expect($no_log->logFilePath())->toBeNull()
        ->and($with_log->logFilePath())->toBe('/tmp/run.log');
});

it('exposes stages array', function (): void {
    $stages = [stageWith('unit'), stageWith('feature')];
    $r = new TestRunResult($stages);

    expect($r->stages())->toBe($stages);
});
