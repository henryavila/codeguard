<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Install;

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * Persists the user's PHPStan extension selection at
 * `.codeguard/phpstan-extensions.yaml` so `codeguard:install --refresh-stubs`
 * reuses the choices on subsequent runs.
 */
final class PhpstanExtensionStore
{
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly string $path,
    ) {}

    /**
     * @return list<PhpstanExtension>
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

        if (! is_array($parsed) || ! isset($parsed['enabled']) || ! is_array($parsed['enabled'])) {
            return [];
        }

        $result = [];
        foreach ($parsed['enabled'] as $value) {
            if (! is_string($value)) {
                continue;
            }

            $case = PhpstanExtension::tryFrom($value);
            if ($case !== null) {
                $result[] = $case;
            }
        }

        return $result;
    }

    /**
     * @param  list<PhpstanExtension>  $extensions
     */
    public function save(array $extensions): void
    {
        $payload = [
            'version' => 1,
            'generated_by' => 'codeguard:install',
            'enabled' => array_map(
                static fn (PhpstanExtension $ext): string => $ext->value,
                $extensions,
            ),
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
            "# CodeGuard — remembered PHPStan extension selection from `codeguard:install`.\n"
            ."# Re-running the installer reuses these choices. Delete this file to be\n"
            ."# prompted again with defaults (all extensions enabled).\n\n"
            .$yaml,
        );
    }

    private function ensureDirectory(string $directory): void
    {
        if (! $this->filesystem->isDirectory($directory)) {
            $this->filesystem->makeDirectory($directory, 0o755, recursive: true);
        }
    }
}
