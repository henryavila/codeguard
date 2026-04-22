<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Commands\Telemetry;

use Henryavila\Codeguard\Telemetry\TelemetryStateStore;
use Illuminate\Console\Command;

final class ClearCommand extends Command
{
    protected $signature = 'codeguard:telemetry:clear
        {--force : Skip the interactive confirmation}';

    protected $description = 'Delete all recorded telemetry .jsonl files';

    public function handle(TelemetryStateStore $store): int
    {
        // Reuse the state store's directory as the jsonl search root so the
        // command honours whatever container override tests provide.
        $codeguardDir = dirname($store->path());
        $pattern = $codeguardDir.DIRECTORY_SEPARATOR.'telemetry*.jsonl';
        $files = glob($pattern) ?: [];

        if ($files === []) {
            $this->components->info('No telemetry files to clear.');

            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            $confirmed = $this->confirm(
                sprintf('Delete %d telemetry file(s)?', count($files)),
                default: false,
            );
            if (! $confirmed) {
                $this->components->warn('Aborted — nothing deleted.');

                return self::FAILURE;
            }
        }

        $deleted = 0;
        foreach ($files as $file) {
            if (@unlink($file)) {
                $deleted++;
            }
        }

        $this->components->info(sprintf('Cleared %d telemetry file(s).', $deleted));

        return self::SUCCESS;
    }
}
