<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Install;

use Illuminate\Filesystem\Filesystem;

/**
 * Ensures `.codeguard/` and `.codeguard/.gitignore` exist in the consumer
 * project before any local-state files (telemetry jsonl, baselines,
 * extension/decision yamls) land there.
 *
 * Phase B's telemetry Recorder appends to `.codeguard/telemetry.jsonl`;
 * without this initializer running first, the file would be missing from
 * .gitignore on fresh installs and a single `git add -A` would leak PII
 * into the repository history. Running on every `codeguard:install` keeps
 * the canonical entries drifting in sync when we add new local artifacts
 * without forcing users to re-init.
 *
 * Idempotent: existing custom entries are preserved, canonical entries
 * already present are not duplicated, and the file is only rewritten
 * when content changes.
 */
class CodeguardDirectoryInitializer
{
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly string $basePath,
    ) {}

    public function initialize(): void
    {
        $dir = $this->basePath.DIRECTORY_SEPARATOR.'.codeguard';

        if (! $this->filesystem->isDirectory($dir)) {
            $this->filesystem->makeDirectory($dir, recursive: true);
        }

        $gitignorePath = $dir.DIRECTORY_SEPARATOR.'.gitignore';
        $existing = $this->filesystem->exists($gitignorePath)
            ? (string) $this->filesystem->get($gitignorePath)
            : '';

        $updated = $this->mergeRequiredEntries($existing);

        if ($updated === $existing) {
            return;
        }

        $this->filesystem->put($gitignorePath, $updated);
    }

    /**
     * @return list<string>
     */
    public static function requiredEntries(): array
    {
        return [
            'telemetry.jsonl',
            'telemetry-*.jsonl',
            'baseline.json',
            'phpstan-extensions.yaml',
            'layer-decisions.yaml',
        ];
    }

    private function mergeRequiredEntries(string $existing): string
    {
        /** @var list<string> $lines */
        $lines = preg_split('/\R/', $existing) ?: [];

        // Drop trailing empty line so appends don't create double blanks;
        // we re-add the final "\n" at the end.
        while ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        foreach (self::requiredEntries() as $entry) {
            if (! in_array($entry, $lines, strict: true)) {
                $lines[] = $entry;
            }
        }

        return implode("\n", $lines)."\n";
    }
}
