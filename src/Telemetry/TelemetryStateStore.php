<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Telemetry;

/**
 * Durable on/off switch for telemetry, read and written by the
 * `codeguard:telemetry:enable` and `codeguard:telemetry:disable` commands.
 *
 * Lives at `.codeguard/telemetry-state.json` in the consumer project so
 * the decision persists across command invocations without touching
 * `config/codeguard.php` (which the user may have customised, vendored,
 * or symlinked).
 *
 * Precedence inside the service-provider ConfigGate binding:
 *   1. this file's value (if present)
 *   2. `codeguard.telemetry.enabled` config value (env + default)
 */
final class TelemetryStateStore
{
    public function __construct(
        private readonly string $stateFilePath,
    ) {}

    /**
     * Returns the persisted flag, or null when the file is absent.
     */
    public function read(): ?bool
    {
        if (! is_file($this->stateFilePath)) {
            return null;
        }

        $raw = @file_get_contents($this->stateFilePath);
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || ! array_key_exists('enabled', $decoded)) {
            return null;
        }

        return (bool) $decoded['enabled'];
    }

    public function write(bool $enabled): bool
    {
        $dir = dirname($this->stateFilePath);
        if (! is_dir($dir) && ! @mkdir($dir, 0o755, recursive: true) && ! is_dir($dir)) {
            return false;
        }

        $payload = json_encode(
            ['enabled' => $enabled],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        if ($payload === false) {
            return false;
        }

        // Atomic: write to sibling tmp file then rename. Matches the pattern
        // established by ComposerAllowPluginsCheck::writeAtomic — a SIGINT
        // mid-write cannot leave the state file truncated.
        $tmp = $this->stateFilePath.'.tmp';
        if (@file_put_contents($tmp, $payload."\n") === false) {
            return false;
        }

        if (! @rename($tmp, $this->stateFilePath)) {
            @unlink($tmp);

            return false;
        }

        return true;
    }

    public function path(): string
    {
        return $this->stateFilePath;
    }
}
