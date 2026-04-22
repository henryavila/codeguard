<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Telemetry;

use Closure;
use DateTimeImmutable;

/**
 * Size-based rotation + retention for the active telemetry jsonl.
 *
 * When the active file exceeds `$maxBytes`, it is renamed to
 * `{basename}-{YYYY-MM-DD-HHMMSS}.{ext}` via an atomic FS rename; a fresh
 * active file is implicitly created by the next JsonlWriter append.
 *
 * After rotating, only the `$retain` most recent archives are kept; the
 * rest are unlinked. Retention is evaluated on mtime descending so that
 * clock-skew between rotations still preserves "newest wins".
 */
final class Rotator
{
    /**
     * @param  Closure(): DateTimeImmutable  $clock
     */
    public function __construct(
        private readonly int $maxBytes = 10 * 1024 * 1024,
        private readonly int $retain = 5,
        private readonly ?Closure $clock = null,
    ) {}

    public function rotateIfNeeded(string $activePath): void
    {
        if (! is_file($activePath)) {
            return;
        }

        $size = @filesize($activePath);
        if ($size === false || $size < $this->maxBytes) {
            return;
        }

        $archive = $this->archivePath($activePath);
        if (! @rename($activePath, $archive)) {
            return;
        }

        $this->pruneArchives($activePath);
    }

    private function archivePath(string $activePath): string
    {
        $now = ($this->clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable)();
        $stamp = $now->format('Y-m-d-His');

        $dir = dirname($activePath);
        $ext = pathinfo($activePath, PATHINFO_EXTENSION);
        $base = pathinfo($activePath, PATHINFO_FILENAME);

        $archive = $dir.DIRECTORY_SEPARATOR.$base.'-'.$stamp.($ext !== '' ? '.'.$ext : '');

        // Guard against collision if two rotations land in the same second.
        $counter = 1;
        while (file_exists($archive)) {
            $archive = $dir.DIRECTORY_SEPARATOR.$base.'-'.$stamp.'-'.$counter.($ext !== '' ? '.'.$ext : '');
            $counter++;
        }

        return $archive;
    }

    private function pruneArchives(string $activePath): void
    {
        $dir = dirname($activePath);
        $ext = pathinfo($activePath, PATHINFO_EXTENSION);
        $base = pathinfo($activePath, PATHINFO_FILENAME);

        $pattern = $dir.DIRECTORY_SEPARATOR.$base.'-*'.($ext !== '' ? '.'.$ext : '');
        $archives = glob($pattern) ?: [];

        if (count($archives) <= $this->retain) {
            return;
        }

        usort($archives, static fn (string $a, string $b): int => (@filemtime($b) ?: 0) <=> (@filemtime($a) ?: 0));

        foreach (array_slice($archives, $this->retain) as $old) {
            @unlink($old);
        }
    }
}
