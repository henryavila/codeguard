<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Install;

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * Persists wizard decisions at `.codeguard/layer-decisions.yaml` so that
 * `codeguard:install --refresh-stubs` re-runs can skip prompts for
 * already-classified namespaces.
 */
final class LayerDecisionStore
{
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly string $path,
    ) {}

    /**
     * Load saved decisions. Returns `namespace => layerName` (empty string
     * for explicit skip).
     *
     * @return array<string, string>
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

        if (! is_array($parsed) || ! isset($parsed['decisions']) || ! is_array($parsed['decisions'])) {
            return [];
        }

        $decisions = [];

        foreach ($parsed['decisions'] as $namespace => $layer) {
            if (! is_string($namespace)) {
                continue;
            }

            if ($layer === null) {
                $decisions[$namespace] = '';

                continue;
            }

            if (is_string($layer)) {
                $decisions[$namespace] = $layer;
            }
        }

        return $decisions;
    }

    public function save(WizardResult $result): void
    {
        if ($result->isEmpty() && ! $this->filesystem->exists($this->path)) {
            return;
        }

        $payload = [
            'version' => 1,
            'generated_by' => 'codeguard:install',
            'decisions' => $this->normalizeDecisions($result),
            'custom_layers' => $result->customLayers,
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
            "# CodeGuard — remembered Deptrac layer decisions from `codeguard:install` wizard.\n"
            ."# Re-running the installer reuses these answers. Delete this file (or a\n"
            ."# specific key) to be prompted again for that namespace.\n\n"
            .$yaml,
        );
    }

    /**
     * @return array<string, ?string>
     */
    private function normalizeDecisions(WizardResult $result): array
    {
        $decisions = [];

        foreach ($result->decisions as $decision) {
            $decisions[$decision->namespace] = $decision->layerName;
        }

        return $decisions;
    }

    private function ensureDirectory(string $directory): void
    {
        if (! $this->filesystem->isDirectory($directory)) {
            $this->filesystem->makeDirectory($directory, 0o755, recursive: true);
        }
    }
}
