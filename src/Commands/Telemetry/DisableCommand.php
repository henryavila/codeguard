<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Commands\Telemetry;

use Henryavila\Codeguard\Telemetry\TelemetryStateStore;
use Illuminate\Console\Command;

final class DisableCommand extends Command
{
    protected $signature = 'codeguard:telemetry:disable';

    protected $description = 'Disable local telemetry recording';

    public function handle(TelemetryStateStore $store): int
    {
        if (! $store->write(false)) {
            $this->components->error('Failed to write telemetry state file.');

            return self::FAILURE;
        }

        $this->components->info('Telemetry disabled. Existing .jsonl files are preserved.');
        $this->line('  Re-enable:    <info>php artisan codeguard:telemetry:enable</info>');
        $this->line('  Delete logs:  <info>php artisan codeguard:telemetry:clear</info>');

        return self::SUCCESS;
    }
}
