<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Telemetry;

/**
 * Single-line JSONL appender with cross-process locking.
 *
 * flock(EX) serialises concurrent writers (e.g. two git hooks running at
 * the same time). The write is the one encoded-line call — atomic at the
 * kernel level below the PIPE_BUF limit (4 KB on Linux), and flock covers
 * the rest.
 *
 * Returns false on any I/O failure; the caller (Recorder) swallows the
 * failure so telemetry NEVER crashes a user command.
 */
final class JsonlWriter
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function append(string $path, array $payload): bool
    {
        $dir = dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0o755, recursive: true) && ! is_dir($dir)) {
            return false;
        }

        $encoded = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        if ($encoded === false) {
            return false;
        }

        $handle = @fopen($path, 'a');
        if ($handle === false) {
            return false;
        }

        try {
            if (! @flock($handle, LOCK_EX)) {
                return false;
            }

            $written = @fwrite($handle, $encoded."\n");
            @fflush($handle);
            @flock($handle, LOCK_UN);

            return $written !== false && $written === strlen($encoded) + 1;
        } finally {
            @fclose($handle);
        }
    }
}
