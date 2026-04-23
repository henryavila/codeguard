<?php

declare(strict_types=1);

use Henryavila\Codeguard\Testing\StageConfig;

it('hydrates all fields from a complete array', function (): void {
    $stage = StageConfig::fromArray('php-main', [
        'enabled' => true,
        'label' => 'PHP Main Suite',
        'phase' => 2,
        'description' => 'Unit + Feature + Integration',
        'command' => ['./vendor/bin/pest', '--testsuite=Unit,Feature'],
        'env' => ['APP_ENV' => 'testing'],
        'report_type' => 'junit',
        'report_file' => 'php-main.xml',
        'report_arg_prefix' => '--log-junit=',
        'fast_fail_arguments' => ['--bail'],
    ]);

    expect($stage->key)->toBe('php-main')
        ->and($stage->enabled)->toBeTrue()
        ->and($stage->label)->toBe('PHP Main Suite')
        ->and($stage->phase)->toBe(2)
        ->and($stage->description)->toBe('Unit + Feature + Integration')
        ->and($stage->command)->toBe(['./vendor/bin/pest', '--testsuite=Unit,Feature'])
        ->and($stage->env)->toBe(['APP_ENV' => 'testing'])
        ->and($stage->reportType)->toBe('junit')
        ->and($stage->reportFile)->toBe('php-main.xml')
        ->and($stage->reportArgPrefix)->toBe('--log-junit=')
        ->and($stage->fastFailArguments)->toBe(['--bail']);
});

it('supplies sensible defaults for optional fields', function (): void {
    $stage = StageConfig::fromArray('unit', []);

    expect($stage->enabled)->toBeFalse()
        ->and($stage->label)->toBe('Unit')
        ->and($stage->phase)->toBe(1)
        ->and($stage->description)->toBe('')
        ->and($stage->command)->toBe([])
        ->and($stage->env)->toBe([])
        ->and($stage->reportType)->toBeNull()
        ->and($stage->reportFile)->toBeNull()
        ->and($stage->reportArgPrefix)->toBeNull()
        ->and($stage->fastFailArguments)->toBe([]);
});

it('derives label from key when label missing (ucfirst)', function (): void {
    $stage = StageConfig::fromArray('browser', ['enabled' => true]);

    expect($stage->label)->toBe('Browser');
});

it('treats null report fields as null (not empty string)', function (): void {
    $stage = StageConfig::fromArray('prepare', [
        'enabled' => true,
        'command' => ['php', 'artisan', 'test:prepare'],
        'report_type' => null,
        'report_file' => null,
        'report_arg_prefix' => null,
    ]);

    expect($stage->reportType)->toBeNull()
        ->and($stage->reportFile)->toBeNull()
        ->and($stage->reportArgPrefix)->toBeNull();
});

it('normalizes command to list<string> even if given associative array', function (): void {
    $stage = StageConfig::fromArray('unit', [
        'command' => ['bin' => './vendor/bin/pest', 'suite' => '--testsuite=Unit'],
    ]);

    expect($stage->command)->toBe(['./vendor/bin/pest', '--testsuite=Unit']);
});

it('casts phase to int when config supplies string', function (): void {
    $stage = StageConfig::fromArray('unit', ['phase' => '3']);

    expect($stage->phase)->toBe(3);
});
