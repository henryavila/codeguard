<?php

declare(strict_types=1);

use Henryavila\Codeguard\Telemetry\EventStatus;

it('exposes three canonical statuses', function (): void {
    expect(EventStatus::cases())->toHaveCount(3);
});

it('backs values with lowercase strings', function (): void {
    expect(EventStatus::Ok->value)->toBe('ok')
        ->and(EventStatus::Fail->value)->toBe('fail')
        ->and(EventStatus::Skip->value)->toBe('skip');
});
