<?php

declare(strict_types=1);

use Henryavila\Codeguard\Analyze\Severity;

it('parses known severity values and rejects unknown', function (): void {
    expect(Severity::from('critical'))->toBe(Severity::Critical)
        ->and(Severity::tryFrom('bogus'))->toBeNull();
});

it('orders severities by weight', function (): void {
    expect(Severity::Critical->weight())->toBeGreaterThan(Severity::Warning->weight())
        ->and(Severity::Warning->weight())->toBeGreaterThan(Severity::Suggestion->weight());
});

it('meets a threshold only when at least as severe', function (): void {
    expect(Severity::Critical->meets(Severity::Warning))->toBeTrue()
        ->and(Severity::Warning->meets(Severity::Warning))->toBeTrue()
        ->and(Severity::Suggestion->meets(Severity::Warning))->toBeFalse();
});
