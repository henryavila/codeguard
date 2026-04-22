<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Commands;

use Henryavila\Codeguard\Install\StubOverrides;
use Henryavila\Codeguard\Install\StubRegistry;
use Henryavila\Codeguard\Testing\Preset;
use Illuminate\Console\Command;

final class CodeguardInstallOverrideCommand extends Command
{
    protected $signature = 'codeguard:install:override
        {path : Relative path of the stub to mark as permanently customized (e.g. phpstan.neon)}
        {--remove : Remove the path from .codeguard/stub-overrides.yaml instead of adding it.}';

    protected $description = 'Mark (or unmark) a stub as permanently customized so `codeguard:install` skips it silently.';

    public function handle(StubOverrides $overrides, StubRegistry $registry): int
    {
        $path = (string) $this->argument('path');
        $path = trim($path);

        if ($path === '') {
            $this->components->error('Path argument is required.');

            return self::INVALID;
        }

        if ((bool) $this->option('remove')) {
            if (! $overrides->contains($path)) {
                $this->components->info("'{$path}' is not in the overrides list — nothing to remove.");

                return self::SUCCESS;
            }

            $overrides->remove($path);
            $this->components->info("Removed '{$path}' from .codeguard/stub-overrides.yaml. It will be prompted again on the next `codeguard:install`.");

            return self::SUCCESS;
        }

        if (! $this->isKnownStubPath($path, $registry)) {
            $this->components->warn(
                "'{$path}' is not a known stub path for any preset. Adding it anyway — but no stub can target it.",
            );
        }

        $overrides->add($path);
        $this->components->info("Added '{$path}' to .codeguard/stub-overrides.yaml. `codeguard:install` will skip it (use --refresh-stubs to force).");

        return self::SUCCESS;
    }

    private function isKnownStubPath(string $path, StubRegistry $registry): bool
    {
        foreach (Preset::cases() as $preset) {
            foreach ($registry->stubsFor($preset) as $stub) {
                if ($stub->targetRelativePath === $path) {
                    return true;
                }
            }
        }

        return false;
    }
}
