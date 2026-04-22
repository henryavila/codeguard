<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Commands\Telemetry;

use Henryavila\Codeguard\Telemetry\TelemetryStateStore;
use Illuminate\Console\Command;

final class EnableCommand extends Command
{
    protected $signature = 'codeguard:telemetry:enable';

    protected $description = 'Enable local telemetry recording to .codeguard/telemetry.jsonl';

    public function handle(TelemetryStateStore $store): int
    {
        if (! $store->write(true)) {
            $this->components->error('Failed to write telemetry state file.');

            return self::FAILURE;
        }

        $this->components->info('Telemetry enabled. Events will append to .codeguard/telemetry.jsonl.');
        $this->line('  Disable with: <info>php artisan codeguard:telemetry:disable</info>');
        $this->line('  Delete logs:  <info>php artisan codeguard:telemetry:clear</info>');

        return self::SUCCESS;
    }
}
