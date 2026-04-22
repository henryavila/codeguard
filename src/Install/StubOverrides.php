<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Install;

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * Tracks stubs the user has marked as permanently customized. Files listed
 * here are NEVER prompted or overwritten by `codeguard:install` — even when
 * stub content drifts — unless `--refresh-stubs` is passed (force flag).
 *
 * Persisted at `.codeguard/stub-overrides.yaml`:
 *
 *   overrides:
 *     - phpstan.neon
 *     - deptrac.yaml
 *
 * Paths are compared against `StubDefinition::$targetRelativePath` (the
 * path relative to the project root), so they live in the same coordinate
 * system StubPublisher already uses.
 */
final class StubOverrides
{
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly string $path,
    ) {}

    /**
     * @return list<string>
     */
    public function load(): array
    {
        if (! $this->filesystem->exists($this->path)) {
            return [];
        }

        try {
            /** @var array<string, mixed>|null $parsed */
            $parsed = Yaml::parse($this->filesystem->get($this->path));
        } catch (\Throwable) {
            return [];
        }

        if (! is_array($parsed) || ! isset($parsed['overrides']) || ! is_array($parsed['overrides'])) {
            return [];
        }

        $result = [];
        foreach ($parsed['overrides'] as $value) {
            if (is_string($value) && $value !== '') {
                $result[] = $this->normalize($value);
            }
        }

        return array_values(array_unique($result));
    }

    public function contains(string $targetRelativePath): bool
    {
        return in_array($this->normalize($targetRelativePath), $this->load(), strict: true);
    }

    public function add(string $targetRelativePath): void
    {
        $normalized = $this->normalize($targetRelativePath);
        $existing = $this->load();

        if (in_array($normalized, $existing, strict: true)) {
            return;
        }

        $existing[] = $normalized;
        $this->save($existing);
    }

    public function remove(string $targetRelativePath): void
    {
        $normalized = $this->normalize($targetRelativePath);
        $existing = $this->load();

        $filtered = array_values(array_filter(
            $existing,
            static fn (string $path): bool => $path !== $normalized,
        ));

        if ($filtered === $existing) {
            return;
        }

        $this->save($filtered);
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @param  list<string>  $paths
     */
    private function save(array $paths): void
    {
        sort($paths);

        $payload = [
            'version' => 1,
            'generated_by' => 'codeguard:install / codeguard:install:override',
            'overrides' => $paths,
        ];

        $yaml = Yaml::dump(
            input: $payload,
            inline: 4,
            indent: 2,
            flags: Yaml::DUMP_OBJECT_AS_MAP,
        );

        $this->ensureDirectory(dirname($this->path));
        $this->filesystem->put(
            $this->path,
            "# CodeGuard — stubs marked as permanently customized.\n"
            ."# Files listed here are skipped silently by `codeguard:install` so\n"
            ."# local edits survive re-runs. Remove an entry to be prompted again,\n"
            ."# or pass --refresh-stubs to force overwrite regardless of this list.\n\n"
            .$yaml,
        );
    }

    private function normalize(string $path): string
    {
        $path = trim($path);
        $path = str_replace('\\', '/', $path);

        return ltrim($path, '/');
    }

    private function ensureDirectory(string $directory): void
    {
        if (! $this->filesystem->isDirectory($directory)) {
            $this->filesystem->makeDirectory($directory, 0o755, recursive: true);
        }
    }
}
