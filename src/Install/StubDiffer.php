<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Install;

use Illuminate\Filesystem\Filesystem;
use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder;

final class StubDiffer
{
    public function __construct(
        private readonly Filesystem $filesystem,
    ) {}

    /**
     * Returns null when files are identical, otherwise a unified diff string.
     */
    public function diff(string $existingPath, string $incomingStubPath): ?string
    {
        if (! $this->filesystem->exists($existingPath) || ! $this->filesystem->exists($incomingStubPath)) {
            return null;
        }

        $existing = $this->filesystem->get($existingPath);
        $incoming = $this->filesystem->get($incomingStubPath);

        if ($existing === $incoming) {
            return null;
        }

        $header = sprintf(
            "--- %s (existing)\n+++ %s (stub)\n",
            basename($existingPath),
            basename($incomingStubPath),
        );

        $builder = new UnifiedDiffOutputBuilder($header, true);
        $differ = new Differ($builder);

        return $differ->diff($existing, $incoming);
    }

    /**
     * Colorize a unified diff for terminal output (ANSI-green for +, ANSI-red for -).
     */
    public function colorize(string $diff): string
    {
        $lines = explode("\n", $diff);
        $colored = [];

        foreach ($lines as $line) {
            if ($line === '') {
                $colored[] = $line;

                continue;
            }

            $colored[] = match (true) {
                str_starts_with($line, '+++') || str_starts_with($line, '---') => "<fg=yellow>{$line}</>",
                str_starts_with($line, '@@') => "<fg=cyan>{$line}</>",
                str_starts_with($line, '+') => "<fg=green>{$line}</>",
                str_starts_with($line, '-') => "<fg=red>{$line}</>",
                default => $line,
            };
        }

        return implode("\n", $colored);
    }
}
