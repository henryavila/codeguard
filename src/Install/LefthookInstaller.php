<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Install;

use Symfony\Component\Process\Process;

enum LefthookInstallStatus: string
{
    case Installed = 'installed';
    case BinaryMissing = 'binary-missing';
    case Skipped = 'skipped';
    case Failed = 'failed';
}

final readonly class LefthookInstallResult
{
    public function __construct(
        public LefthookInstallStatus $status,
        public ?string $message = null,
    ) {}
}

class LefthookInstaller
{
    public function __construct(
        private readonly string $basePath,
    ) {}

    public function install(EnvironmentInfo $env): LefthookInstallResult
    {
        if (! $env->hasLefthookBinary) {
            return new LefthookInstallResult(
                status: LefthookInstallStatus::BinaryMissing,
                message: $this->installInstructions(),
            );
        }

        $process = new Process(['lefthook', 'install'], $this->basePath);
        $process->setTimeout(30);

        try {
            $process->run();
        } catch (\Throwable $exception) {
            return new LefthookInstallResult(
                status: LefthookInstallStatus::Failed,
                message: $exception->getMessage(),
            );
        }

        if (! $process->isSuccessful()) {
            $error = trim($process->getErrorOutput() ?: $process->getOutput());

            return new LefthookInstallResult(
                status: LefthookInstallStatus::Failed,
                message: $error !== '' ? $error : 'lefthook install exited non-zero',
            );
        }

        return new LefthookInstallResult(
            status: LefthookInstallStatus::Installed,
            message: trim($process->getOutput()),
        );
    }

    private function installInstructions(): string
    {
        return implode("\n", [
            'Lefthook binary not found in PATH. Install with one of:',
            '  brew install lefthook             (macOS)',
            '  apt install lefthook              (Debian/Ubuntu)',
            '  composer global require evilmartians/lefthook',
            '  npm install -g @evilmartians/lefthook',
            'Then re-run: php artisan codeguard:install',
        ]);
    }
}
