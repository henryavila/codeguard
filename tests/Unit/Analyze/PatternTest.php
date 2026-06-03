<?php

declare(strict_types=1);

use Henryavila\Codeguard\Analyze\Pattern;
use Henryavila\Codeguard\Analyze\Severity;

it('maps a pattern array into an immutable DTO', function (): void {
    $pattern = Pattern::fromArray('no-god-object', [
        'name' => 'no-god-object',
        'description' => 'Classes should not do everything',
        'category' => 'solid',
        'layer' => 'core',
        'severity' => 'critical',
        'classification' => 'mvp',
        'detection' => [
            'signals' => [
                ['type' => 'file', 'value' => '**/*.php'],
                ['type' => 'directory', 'value' => 'app/Services'],
            ],
            'confidence' => 'medium',
        ],
        'verification' => ['rules' => ['rule one', 'rule two']],
        'examples' => ['correct' => 'GOOD', 'violation' => 'BAD'],
        'related_patterns' => ['single-responsibility'],
    ]);

    expect($pattern->key)->toBe('no-god-object')
        ->and($pattern->severity)->toBe(Severity::Critical)
        ->and($pattern->confidence)->toBe('medium')
        ->and($pattern->detectionSignals)->toHaveCount(2)
        ->and($pattern->detectionSignals[0]->type)->toBe('file')
        ->and($pattern->detectionSignals[0]->value)->toBe('**/*.php')
        ->and($pattern->verificationRules)->toBe(['rule one', 'rule two'])
        ->and($pattern->examplesCorrect)->toBe('GOOD')
        ->and($pattern->examplesViolation)->toBe('BAD')
        ->and($pattern->relatedPatterns)->toBe(['single-responsibility']);
});

it('defaults severity to warning and confidence to medium when absent', function (): void {
    $pattern = Pattern::fromArray('x', ['severity' => 'bogus']);

    expect($pattern->severity)->toBe(Severity::Warning)
        ->and($pattern->confidence)->toBe('medium')
        ->and($pattern->detectionSignals)->toBe([])
        ->and($pattern->verificationRules)->toBe([]);
});
