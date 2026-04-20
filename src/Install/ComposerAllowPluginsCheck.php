<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Install;

use Illuminate\Filesystem\Filesystem;

/**
 * Inspects and patches `config.allow-plugins` in a consumer's composer.json.
 *
 * Composer 2.2+ blocks Composer plugins by default unless the plugin is
 * explicitly listed as `true` under `config.allow-plugins`. When codeguard
 * is installed into a project that doesn't list `captainhook/hook-installer`
 * there, the post-install hook wiring silently fails — the user then runs
 * `codeguard:install`, the captainhook binary isn't present, and the gate
 * status shows "binary missing" without context.
 *
 * This class exists so the installer can:
 *   - Detect the state (`check()`) and surface a warning with remediation.
 *   - Optionally flip the config (`allow()`) when the user opts in.
 *
 * Wildcard patterns (e.g. `captainhook/*`) are honored during read so we
 * don't nag users who have already opted in at the vendor level.
 */
class ComposerAllowPluginsCheck
{
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly string $composerJsonPath,
    ) {}

    public function check(string $plugin): AllowPluginsStatus
    {
        $json = $this->readJson();

        if ($json === null) {
            return AllowPluginsStatus::Unknown;
        }

        $allowPlugins = $this->extractAllowPlugins($json);

        if ($allowPlugins === null) {
            return AllowPluginsStatus::NotAllowed;
        }

        if (array_key_exists($plugin, $allowPlugins)) {
            return $allowPlugins[$plugin] === true
                ? AllowPluginsStatus::Allowed
                : AllowPluginsStatus::NotAllowed;
        }

        foreach ($allowPlugins as $pattern => $value) {
            if ($value === true && fnmatch($pattern, $plugin)) {
                return AllowPluginsStatus::Allowed;
            }
        }

        return AllowPluginsStatus::NotAllowed;
    }

    public function allow(string $plugin): bool
    {
        $json = $this->readJson();

        if ($json === null) {
            return false;
        }

        // Refuse to overwrite `allow-plugins: false` — Composer's shorthand
        // for "block ALL plugins". Silently expanding it to a map would
        // destroy the project's deny-all policy. The user must flip the
        // value explicitly; we only help when allow-plugins is already a
        // map (or absent).
        $existingAllowPlugins = $json['config']['allow-plugins'] ?? null;
        if ($existingAllowPlugins !== null && ! is_array($existingAllowPlugins)) {
            return false;
        }

        if (! isset($json['config']) || ! is_array($json['config'])) {
            $json['config'] = [];
        }

        if (! isset($json['config']['allow-plugins']) || ! is_array($json['config']['allow-plugins'])) {
            $json['config']['allow-plugins'] = [];
        }

        $json['config']['allow-plugins'][$plugin] = true;

        $encoded = json_encode(
            $json,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        if ($encoded === false) {
            return false;
        }

        return $this->writeAtomic($encoded."\n");
    }

    /**
     * Write via temp file + rename so a SIGINT / power loss mid-write
     * cannot leave composer.json truncated. Composer itself does this
     * for the same reason.
     */
    private function writeAtomic(string $content): bool
    {
        $dir = dirname($this->composerJsonPath);
        $temp = tempnam($dir, '.composer-');

        if ($temp === false) {
            return false;
        }

        if (file_put_contents($temp, $content) === false) {
            @unlink($temp);

            return false;
        }

        // Preserve the existing file's permissions when possible so we
        // don't unexpectedly widen access on the consumer's composer.json.
        if (file_exists($this->composerJsonPath)) {
            $perms = fileperms($this->composerJsonPath);
            if ($perms !== false) {
                @chmod($temp, $perms & 0o777);
            }
        }

        if (! @rename($temp, $this->composerJsonPath)) {
            @unlink($temp);

            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJson(): ?array
    {
        if (! $this->filesystem->exists($this->composerJsonPath)) {
            return null;
        }

        $content = $this->filesystem->get($this->composerJsonPath);

        $decoded = json_decode($content, associative: true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<string, bool>|null
     */
    private function extractAllowPlugins(array $json): ?array
    {
        $config = $json['config'] ?? null;

        if (! is_array($config)) {
            return null;
        }

        $allow = $config['allow-plugins'] ?? null;

        if (! is_array($allow)) {
            return null;
        }

        /** @var array<string, bool> $filtered */
        $filtered = [];
        foreach ($allow as $key => $value) {
            if (is_string($key) && is_bool($value)) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }
}
