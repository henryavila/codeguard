<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Install;

use Symfony\Component\Process\Process;

/**
 * Ensures CaptainHook's Git hooks are wired into .git/hooks/* for the
 * consumer project.
 *
 * CaptainHook ships a Composer plugin (captainhook/hook-installer) that
 * SHOULD activate hooks automatically on every `composer install` /
 * `composer update`. This class exists to:
 *
 *   1. Verify the plugin did its job (binary present under vendor/bin/).
 *   2. Provide a manual fallback for edge cases — some teams configure
 *      Composer with --no-scripts, or pipelines explicitly disable
 *      plugins; in those cases we fall back to `vendor/bin/captainhook
 *      install`.
 *
 * The binary lives inside the project's vendor directory (not in $PATH),
 * which is why detection uses a filesystem check rather than `which`.
 */
class CaptainhookInstaller
{
    public function __construct(
        private readonly string $basePath,
    ) {}

    public function install(EnvironmentInfo $env): CaptainhookInstallResult
    {
        if (! $env->hasCaptainhookBinary) {
            return new CaptainhookInstallResult(
                status: CaptainhookInstallStatus::BinaryMissing,
                message: $this->remediation(),
            );
        }

        $binary = $this->basePath.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'captainhook';

        // --force: activate every hook non-interactively. Without it,
        //   `install --no-interaction` silently skips all hooks (treats
        //   every unanswered "activate this hook?" prompt as "no"). We
        //   always want codeguard to end with hooks actually installed,
        //   not half-configured.
        // --only-enabled: respect the `enabled: true/false` flags in
        //   captainhook.json. Our stub leaves commit-msg enabled but
        //   empty; post-* hooks aren't declared at all. --only-enabled
        //   filters the "hook X is not configured — skipping" noise we
        //   saw during the first Arch install.
        $process = new Process([$binary, 'install', '--force', '--only-enabled'], $this->basePath);
        $process->setTimeout(30);

        try {
            $process->run();
        } catch (\Throwable $exception) {
            return new CaptainhookInstallResult(
                status: CaptainhookInstallStatus::Failed,
                message: $exception->getMessage(),
            );
        }

        if (! $process->isSuccessful()) {
            $error = trim($process->getErrorOutput() ?: $process->getOutput());

            return new CaptainhookInstallResult(
                status: CaptainhookInstallStatus::Failed,
                message: $error !== '' ? $error : 'captainhook install exited non-zero',
            );
        }

        return new CaptainhookInstallResult(
            status: CaptainhookInstallStatus::Installed,
            message: trim($process->getOutput()),
        );
    }

    private function remediation(): string
    {
        return implode("\n", [
            'CaptainHook binary not found at vendor/bin/captainhook.',
            'This usually means one of the following:',
            '  • `composer install` has not been run yet — run it now.',
            '  • The captainhook/hook-installer plugin is disabled —',
            '    add it to config.allow-plugins in composer.json.',
            '  • --no-scripts was passed to composer — re-run without it.',
            'After fixing, re-run: php artisan codeguard:install',
        ]);
    }
}
