<?php

declare(strict_types=1);

use Henryavila\Codeguard\Install\CaptainhookInstallResult;
use Henryavila\Codeguard\Install\CaptainhookInstallStatus;
use Henryavila\Codeguard\Install\EnvironmentInfo;
use Henryavila\Codeguard\Install\InstallTelemetry;
use Henryavila\Codeguard\Install\PhpstanExtension;
use Henryavila\Codeguard\Telemetry\ConfigGate;
use Henryavila\Codeguard\Telemetry\FieldAllowlist;
use Henryavila\Codeguard\Telemetry\JsonlWriter;
use Henryavila\Codeguard\Telemetry\Recorder;
use Henryavila\Codeguard\Telemetry\Rotator;
use Henryavila\Codeguard\Testing\Preset;

function installTelemetryTempPath(): string
{
    return sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-install-telemetry-'.uniqid().'.jsonl';
}

function installTelemetryFactory(string $path): InstallTelemetry
{
    $recorder = new Recorder(
        gate: new ConfigGate(enabled: true),
        allowlist: new FieldAllowlist(strictMode: true),
        rotator: new Rotator,
        writer: new JsonlWriter,
        activePath: $path,
    );

    return new InstallTelemetry($recorder);
}

/**
 * @return array<int, array<string, mixed>>
 */
function installTelemetryLines(string $path): array
{
    if (! file_exists($path)) {
        return [];
    }
    $lines = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $raw) {
        $decoded = json_decode((string) $raw, true);
        if (is_array($decoded)) {
            $lines[] = $decoded;
        }
    }

    return $lines;
}

it('commandStarted normalises a valid preset flag and emits command.start', function (): void {
    $path = installTelemetryTempPath();

    try {
        installTelemetryFactory($path)->commandStarted('default');

        $lines = installTelemetryLines($path);
        expect($lines)->toHaveCount(1)
            ->and($lines[0]['event'])->toBe('command.start')
            ->and($lines[0]['command'])->toBe('install')
            ->and($lines[0]['preset_flag'])->toBe('default');
    } finally {
        @unlink($path);
    }
});

it('commandStarted downgrades an unknown preset flag to null', function (): void {
    $path = installTelemetryTempPath();

    try {
        installTelemetryFactory($path)->commandStarted('bogus-preset');

        $lines = installTelemetryLines($path);
        expect($lines[0]['preset_flag'])->toBeNull();
    } finally {
        @unlink($path);
    }
});

it('envDetected buckets PHP version to the nearest allowed track', function (string $input, string $expected): void {
    $path = installTelemetryTempPath();

    try {
        installTelemetryFactory($path)->envDetected(new EnvironmentInfo(
            phpVersion: $input,
            composerVersion: '2.7.0',
            nodeVersion: null,
            hasPackageJson: false,
            hasNodeModules: false,
            hasCaptainhookBinary: true,
        ));

        expect(installTelemetryLines($path)[0]['php_version_major_minor'])->toBe($expected);
    } finally {
        @unlink($path);
    }
})->with([
    ['8.3.0', '8.3'],
    ['8.4.2', '8.4'],
    ['8.5.0-dev', '8.5'],
    ['8.2.10', 'other'],
    ['7.4.33', 'other'],
    ['unknown', 'other'],
]);

it('envDetected extracts the composer major version', function (): void {
    $path = installTelemetryTempPath();

    try {
        installTelemetryFactory($path)->envDetected(new EnvironmentInfo(
            phpVersion: '8.3.0',
            composerVersion: '2.7.4',
            nodeVersion: null,
            hasPackageJson: false,
            hasNodeModules: false,
            hasCaptainhookBinary: true,
        ));

        expect(installTelemetryLines($path)[0]['composer_version_major'])->toBe(2);
    } finally {
        @unlink($path);
    }
});

it('presetSelected passes preset value and source straight through', function (): void {
    $path = installTelemetryTempPath();

    try {
        installTelemetryFactory($path)->presetSelected(Preset::Default, 'auto');

        $line = installTelemetryLines($path)[0];
        expect($line['event'])->toBe('install.preset.selected')
            ->and($line['preset'])->toBe('codeguard')
            ->and($line['source'])->toBe('auto');
    } finally {
        @unlink($path);
    }
});

it('presetSelected falls back to auto when source label is unknown', function (): void {
    $path = installTelemetryTempPath();

    try {
        installTelemetryFactory($path)->presetSelected(Preset::Full, 'impossible');

        expect(installTelemetryLines($path)[0]['source'])->toBe('auto');
    } finally {
        @unlink($path);
    }
});

it('phpstanExtensionsSelected serialises enum values as backing strings', function (): void {
    $path = installTelemetryTempPath();

    try {
        installTelemetryFactory($path)->phpstanExtensionsSelected([
            PhpstanExtension::Larastan,
            PhpstanExtension::DeadCode,
        ]);

        $line = installTelemetryLines($path)[0];
        expect($line['event'])->toBe('install.phpstan_extensions.selected')
            ->and($line['count'])->toBe(2)
            ->and($line['enum_values'])->toBe(['larastan', 'dead-code']);
    } finally {
        @unlink($path);
    }
});

it('captainhookActivated maps BinaryMissing to failed', function (): void {
    $path = installTelemetryTempPath();

    try {
        installTelemetryFactory($path)->captainhookActivated(new CaptainhookInstallResult(
            status: CaptainhookInstallStatus::BinaryMissing,
            message: null,
        ));

        $line = installTelemetryLines($path)[0];
        expect($line['activation_status'])->toBe('failed')
            ->and($line['status'])->toBe('fail');
    } finally {
        @unlink($path);
    }
});

it('captainhookActivated emits skip status when skipped', function (): void {
    $path = installTelemetryTempPath();

    try {
        installTelemetryFactory($path)->captainhookActivated(new CaptainhookInstallResult(
            status: CaptainhookInstallStatus::Skipped,
            message: null,
        ));

        $line = installTelemetryLines($path)[0];
        expect($line['activation_status'])->toBe('skipped')
            ->and($line['status'])->toBe('skip');
    } finally {
        @unlink($path);
    }
});

it('commandEnded clamps exit_code to the 0..255 schema range', function (int $raw, int $expected): void {
    $path = installTelemetryTempPath();

    try {
        installTelemetryFactory($path)->commandEnded($raw, 42);

        $line = installTelemetryLines($path)[0];
        expect($line['exit_code'])->toBe($expected)
            ->and($line['duration_ms'])->toBe(42);
    } finally {
        @unlink($path);
    }
})->with([
    [0, 0],
    [2, 2],
    [255, 255],
    [-1, 0],
    [999, 255],
]);

it('nextStepsRendered emits a non-negative count', function (): void {
    $path = installTelemetryTempPath();

    try {
        installTelemetryFactory($path)->nextStepsRendered(4);
        expect(installTelemetryLines($path)[0]['count'])->toBe(4);
        @unlink($path);

        installTelemetryFactory($path)->nextStepsRendered(-5);
        expect(installTelemetryLines($path)[0]['count'])->toBe(0);
    } finally {
        @unlink($path);
    }
});
