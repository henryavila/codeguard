<?php

declare(strict_types=1);

use Henryavila\Codeguard\Install\InstallSummary;
use Henryavila\Codeguard\Install\InstallWarning;
use Henryavila\Codeguard\Install\WarningCode;
use Henryavila\Codeguard\Install\WarningLevel;

it('reports empty when no warnings are recorded', function (): void {
    $summary = new InstallSummary;

    expect($summary->isEmpty())->toBeTrue()
        ->and($summary->hasIssues())->toBeFalse()
        ->and($summary->warnings())->toBe([])
        ->and($summary->highestLevel())->toBeNull();
});

it('collects a single warning and exposes it', function (): void {
    $summary = new InstallSummary;

    $summary->warn(new InstallWarning(
        level: WarningLevel::Warning,
        code: WarningCode::CaptainhookBinaryMissing,
        message: 'CaptainHook binary missing at vendor/bin/captainhook',
        remediation: 'Run composer install',
    ));

    expect($summary->isEmpty())->toBeFalse()
        ->and($summary->hasIssues())->toBeTrue()
        ->and($summary->warnings())->toHaveCount(1)
        ->and($summary->warnings()[0]->code)->toBe(WarningCode::CaptainhookBinaryMissing)
        ->and($summary->highestLevel())->toBe(WarningLevel::Warning);
});

it('orders Error before Warning regardless of insertion order', function (): void {
    $summary = new InstallSummary;

    $summary->warn(new InstallWarning(
        level: WarningLevel::Warning,
        code: WarningCode::NodeMissingForFullPreset,
        message: 'Node missing',
        remediation: 'Install Node 18+',
    ));
    $summary->warn(new InstallWarning(
        level: WarningLevel::Error,
        code: WarningCode::PhpVersionTooLow,
        message: 'PHP 8.2 detected, 8.3 required',
        remediation: 'Upgrade PHP to 8.3+',
    ));
    $summary->warn(new InstallWarning(
        level: WarningLevel::Warning,
        code: WarningCode::ComposerLockStale,
        message: 'composer.lock is older than composer.json',
        remediation: 'Run composer update',
    ));

    $ordered = $summary->warnings();

    expect($ordered)->toHaveCount(3)
        ->and($ordered[0]->level)->toBe(WarningLevel::Error)
        ->and($ordered[0]->code)->toBe(WarningCode::PhpVersionTooLow)
        ->and($ordered[1]->level)->toBe(WarningLevel::Warning)
        ->and($ordered[2]->level)->toBe(WarningLevel::Warning)
        ->and($summary->highestLevel())->toBe(WarningLevel::Error);
});

it('preserves insertion order among same-severity warnings (stable sort)', function (): void {
    $summary = new InstallSummary;

    $first = new InstallWarning(
        level: WarningLevel::Warning,
        code: WarningCode::NodeMissingForFullPreset,
        message: 'first',
        remediation: 'r',
    );
    $second = new InstallWarning(
        level: WarningLevel::Warning,
        code: WarningCode::ComposerLockStale,
        message: 'second',
        remediation: 'r',
    );
    $third = new InstallWarning(
        level: WarningLevel::Warning,
        code: WarningCode::CaptainhookBinaryMissing,
        message: 'third',
        remediation: 'r',
    );

    $summary->warn($first);
    $summary->warn($second);
    $summary->warn($third);

    $ordered = $summary->warnings();

    expect($ordered[0])->toBe($first)
        ->and($ordered[1])->toBe($second)
        ->and($ordered[2])->toBe($third);
});

it('reports highestLevel as Error when any Error is present', function (): void {
    $summary = new InstallSummary;
    $summary->warn(new InstallWarning(
        level: WarningLevel::Warning,
        code: WarningCode::NodeMissingForFullPreset,
        message: 'w',
        remediation: 'r',
    ));
    $summary->warn(new InstallWarning(
        level: WarningLevel::Error,
        code: WarningCode::CaptainhookInstallFailed,
        message: 'e',
        remediation: 'r',
    ));

    expect($summary->highestLevel())->toBe(WarningLevel::Error);
});

it('stores message and remediation exactly as provided', function (): void {
    $warning = new InstallWarning(
        level: WarningLevel::Error,
        code: WarningCode::PhpVersionTooLow,
        message: 'PHP 8.1 detected',
        remediation: 'brew install php@8.3',
    );

    expect($warning->level)->toBe(WarningLevel::Error)
        ->and($warning->code)->toBe(WarningCode::PhpVersionTooLow)
        ->and($warning->message)->toBe('PHP 8.1 detected')
        ->and($warning->remediation)->toBe('brew install php@8.3');
});
