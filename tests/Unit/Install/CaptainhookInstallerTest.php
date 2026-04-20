<?php

declare(strict_types=1);

use Henryavila\Codeguard\Install\CaptainhookInstallResult;
use Henryavila\Codeguard\Install\CaptainhookInstallStatus;
use Henryavila\Codeguard\Install\CaptainhookInstaller;
use Henryavila\Codeguard\Install\EnvironmentInfo;

function makeEnv(bool $hasCaptainhook): EnvironmentInfo
{
    return new EnvironmentInfo(
        phpVersion: '8.3.0',
        composerVersion: '2.7.0',
        nodeVersion: null,
        hasPackageJson: false,
        hasNodeModules: false,
        hasCaptainhookBinary: $hasCaptainhook,
    );
}

it('returns BinaryMissing when captainhook is not in vendor/bin', function (): void {
    $installer = new CaptainhookInstaller(basePath: sys_get_temp_dir());

    $result = $installer->install(makeEnv(hasCaptainhook: false));

    expect($result)->toBeInstanceOf(CaptainhookInstallResult::class)
        ->and($result->status)->toBe(CaptainhookInstallStatus::BinaryMissing)
        ->and($result->message)->toContain('composer install');
});

it('remediation message does not mention brew, apt or npm', function (): void {
    $installer = new CaptainhookInstaller(basePath: sys_get_temp_dir());

    $result = $installer->install(makeEnv(hasCaptainhook: false));

    expect($result->message)->not->toContain('brew')
        ->and($result->message)->not->toContain('apt ')
        ->and($result->message)->not->toContain('npm ');
});

it('returns Installed when the captainhook binary reports success', function (): void {
    $tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-ch-'.uniqid();
    $binDir = $tempDir.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'bin';
    mkdir($binDir, 0o755, recursive: true);

    // Create a fake "captainhook" binary: a shell script that exits 0
    // and prints a predictable success line.
    $fakeBin = $binDir.DIRECTORY_SEPARATOR.'captainhook';
    file_put_contents($fakeBin, "#!/usr/bin/env sh\necho 'Installed CaptainHook in .git/hooks'\nexit 0\n");
    chmod($fakeBin, 0o755);

    try {
        $installer = new CaptainhookInstaller(basePath: $tempDir);
        $result = $installer->install(makeEnv(hasCaptainhook: true));

        expect($result->status)->toBe(CaptainhookInstallStatus::Installed)
            ->and($result->message)->toContain('Installed CaptainHook');
    } finally {
        @unlink($fakeBin);
        @rmdir($binDir);
        @rmdir(dirname($binDir));
        @rmdir($tempDir);
    }
});

it('returns Failed when the captainhook binary exits non-zero', function (): void {
    $tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codeguard-ch-fail-'.uniqid();
    $binDir = $tempDir.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'bin';
    mkdir($binDir, 0o755, recursive: true);

    $fakeBin = $binDir.DIRECTORY_SEPARATOR.'captainhook';
    file_put_contents($fakeBin, "#!/usr/bin/env sh\necho 'boom' >&2\nexit 7\n");
    chmod($fakeBin, 0o755);

    try {
        $installer = new CaptainhookInstaller(basePath: $tempDir);
        $result = $installer->install(makeEnv(hasCaptainhook: true));

        expect($result->status)->toBe(CaptainhookInstallStatus::Failed)
            ->and($result->message)->not->toBeNull();
    } finally {
        @unlink($fakeBin);
        @rmdir($binDir);
        @rmdir(dirname($binDir));
        @rmdir($tempDir);
    }
});
