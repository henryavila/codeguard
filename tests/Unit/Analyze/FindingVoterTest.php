<?php

declare(strict_types=1);

use Henryavila\Codeguard\Analyze\FindingVoter;
use Henryavila\Codeguard\Analyze\PatternMatch;
use Henryavila\Codeguard\Analyze\Severity;

function fvMatch(
    string $key = 'p',
    string $file = '/work/A.php',
    int $line = 10,
    string $message = 'm',
    Severity $severity = Severity::Warning,
    float $confidence = 0.5,
): PatternMatch {
    return new PatternMatch($key, $file, $line, $message, $severity, $confidence);
}

it('keeps a finding present in at least minVotes samples and sets confidence to vote-share', function (): void {
    $survivors = (new FindingVoter)->tally([
        [fvMatch()],
        [fvMatch()],
        [],
    ], minVotes: 2);

    expect($survivors)->toHaveCount(1)
        ->and($survivors[0]->confidence)->toBe(2 / 3);
});

it('drops a finding below the vote threshold', function (): void {
    $survivors = (new FindingVoter)->tally([
        [fvMatch()],
        [],
        [],
    ], minVotes: 2);

    expect($survivors)->toBe([]);
});

it('treats two findings of the same pattern at different lines as distinct votes', function (): void {
    $survivors = (new FindingVoter)->tally([
        [fvMatch(line: 10), fvMatch(line: 20)],
        [fvMatch(line: 10)],
        [fvMatch(line: 10)],
    ], minVotes: 2);

    // line 10 has 3 votes; line 20 has 1 vote and is dropped.
    expect($survivors)->toHaveCount(1)
        ->and($survivors[0]->line)->toBe(10)
        ->and($survivors[0]->confidence)->toBe(1.0);
});

it('counts a finding duplicated within one sample as a single vote', function (): void {
    $survivors = (new FindingVoter)->tally([
        [fvMatch(), fvMatch()], // same key twice in one sample => 1 vote
        [],
        [],
    ], minVotes: 2);

    expect($survivors)->toBe([]);
});

it('distinguishes findings by pattern key and file', function (): void {
    $survivors = (new FindingVoter)->tally([
        [fvMatch(key: 'a'), fvMatch(key: 'b', file: '/work/B.php')],
        [fvMatch(key: 'a')],
        [fvMatch(key: 'b', file: '/work/B.php')],
    ], minVotes: 2);

    expect($survivors)->toHaveCount(2);
});

it('returns empty for zero samples', function (): void {
    expect((new FindingVoter)->tally([], minVotes: 1))->toBe([]);
});

it('keeps the representative finding fields from its first occurrence', function (): void {
    $first = fvMatch(message: 'first message', severity: Severity::Critical, confidence: 0.2);
    $second = fvMatch(message: 'second message', severity: Severity::Warning, confidence: 0.99);

    $survivors = (new FindingVoter)->tally([[$first], [$second]], minVotes: 2);

    expect($survivors)->toHaveCount(1)
        ->and($survivors[0]->message)->toBe('first message')
        ->and($survivors[0]->severity)->toBe(Severity::Critical)
        ->and($survivors[0]->confidence)->toBe(1.0);
});

it('with a single sample and minVotes 1 keeps everything at full vote-share', function (): void {
    $survivors = (new FindingVoter)->tally([
        [fvMatch(key: 'a'), fvMatch(key: 'b')],
    ], minVotes: 1);

    expect($survivors)->toHaveCount(2)
        ->and($survivors[0]->confidence)->toBe(1.0)
        ->and($survivors[1]->confidence)->toBe(1.0);
});
