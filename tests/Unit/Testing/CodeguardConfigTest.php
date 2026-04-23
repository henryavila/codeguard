<?php

declare(strict_types=1);

use Henryavila\Codeguard\Testing\CodeguardConfig;
use Henryavila\Codeguard\Testing\GateConfig;
use Henryavila\Codeguard\Testing\PrepareConfig;
use Henryavila\Codeguard\Testing\Preset;
use Henryavila\Codeguard\Testing\StageConfig;

/**
 * @return array<string, mixed>
 */
function codeguardFixtureConfig(): array
{
    return [
        'mode' => 'ci',
        'preset' => 'codeguard-full',
        'gates' => [
            'pint' => [
                'enabled' => true,
                'command' => './vendor/bin/pint --test',
                'description' => 'Laravel Pint',
            ],
            'phpstan' => [
                'enabled' => false,
                'command' => './vendor/bin/phpstan',
                'description' => 'PHPStan',
            ],
        ],
        'stages' => [
            'unit' => [
                'enabled' => true,
                'label' => 'Unit Tests',
                'phase' => 1,
                'description' => 'Pure unit tests',
                'command' => ['./vendor/bin/pest', '--testsuite=Unit'],
                'env' => ['APP_ENV' => 'testing'],
                'report_type' => 'junit',
                'report_file' => 'unit.xml',
                'report_arg_prefix' => '--log-junit=',
                'fast_fail_arguments' => ['--bail'],
            ],
            'feature' => [
                'enabled' => false,
                'phase' => 1,
                'command' => ['./vendor/bin/pest', '--testsuite=Feature'],
                'env' => [],
                'report_type' => 'junit',
            ],
        ],
        'report_dir' => '/tmp/reports',
        'prepare' => [
            'connection' => 'mysql',
            'dump_path' => '/tmp/dump.sql',
            'hash_path' => '/tmp/.hash',
            'migrations_path' => '/tmp/migrations',
        ],
        'protected_configs' => ['phpstan.neon', 'pint.json'],
        'patterns' => [
            'enabled_presets' => ['core', 'php'],
            'custom_paths' => ['/tmp/patterns'],
            'baseline_path' => '/tmp/baseline.json',
        ],
        'ai_rules' => [
            'targets' => [
                'claude' => true,
                'cursor' => false,
            ],
        ],
    ];
}

it('hydrates top-level scalars and preset from array', function (): void {
    $config = CodeguardConfig::fromArray(codeguardFixtureConfig());

    expect($config->mode)->toBe('ci')
        ->and($config->preset)->toBe(Preset::Full)
        ->and($config->reportDir)->toBe('/tmp/reports')
        ->and($config->baselinePath)->toBe('/tmp/baseline.json');
});

it('falls back to Default preset when value is unknown', function (): void {
    $config = CodeguardConfig::fromArray(['preset' => 'bogus']);

    expect($config->preset)->toBe(Preset::Default);
});

it('hydrates nested GateConfig DTOs keyed by gate name', function (): void {
    $config = CodeguardConfig::fromArray(codeguardFixtureConfig());

    expect($config->gates)->toHaveKeys(['pint', 'phpstan'])
        ->and($config->gates['pint'])->toBeInstanceOf(GateConfig::class)
        ->and($config->gates['pint']->enabled)->toBeTrue()
        ->and($config->gates['pint']->command)->toBe('./vendor/bin/pint --test')
        ->and($config->gates['phpstan']->enabled)->toBeFalse();
});

it('hydrates nested StageConfig DTOs with env map and report metadata', function (): void {
    $config = CodeguardConfig::fromArray(codeguardFixtureConfig());

    expect($config->stages['unit'])->toBeInstanceOf(StageConfig::class)
        ->and($config->stages['unit']->env)->toBe(['APP_ENV' => 'testing'])
        ->and($config->stages['unit']->reportType)->toBe('junit')
        ->and($config->stages['unit']->reportFile)->toBe('unit.xml')
        ->and($config->stages['unit']->reportArgPrefix)->toBe('--log-junit=')
        ->and($config->stages['unit']->fastFailArguments)->toBe(['--bail'])
        ->and($config->stages['unit']->command)->toBe(['./vendor/bin/pest', '--testsuite=Unit']);
});

it('hydrates PrepareConfig from nested array', function (): void {
    $config = CodeguardConfig::fromArray(codeguardFixtureConfig());

    expect($config->prepare)->toBeInstanceOf(PrepareConfig::class)
        ->and($config->prepare->connection)->toBe('mysql')
        ->and($config->prepare->dumpPath)->toBe('/tmp/dump.sql');
});

it('hydrates protected configs, pattern presets and ai-rules targets', function (): void {
    $config = CodeguardConfig::fromArray(codeguardFixtureConfig());

    expect($config->protectedConfigs)->toBe(['phpstan.neon', 'pint.json'])
        ->and($config->enabledPresets)->toBe(['core', 'php'])
        ->and($config->customPatternPaths)->toBe(['/tmp/patterns'])
        ->and($config->aiRulesTargets)->toBe(['claude' => true, 'cursor' => false]);
});

it('enabledGates() returns only gates flagged enabled', function (): void {
    $config = CodeguardConfig::fromArray(codeguardFixtureConfig());

    $enabled = $config->enabledGates();

    expect($enabled)->toHaveCount(1)
        ->and($enabled[0])->toBeInstanceOf(GateConfig::class)
        ->and($enabled[0]->key)->toBe('pint');
});

it('enabledStages() returns only stages flagged enabled', function (): void {
    $config = CodeguardConfig::fromArray(codeguardFixtureConfig());

    $enabled = $config->enabledStages();

    expect($enabled)->toHaveCount(1)
        ->and($enabled[0]->key)->toBe('unit');
});

it('tolerates a completely empty config array', function (): void {
    $config = CodeguardConfig::fromArray([]);

    expect($config->mode)->toBe('default')
        ->and($config->preset)->toBe(Preset::Default)
        ->and($config->gates)->toBe([])
        ->and($config->stages)->toBe([])
        ->and($config->protectedConfigs)->toBe([])
        ->and($config->enabledPresets)->toBe([]);
});
