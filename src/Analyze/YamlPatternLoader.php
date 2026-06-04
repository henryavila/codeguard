<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Analyze;

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads {@see Pattern}s from YAML. Primary source is the PACKAGE's own
 * `resources/patterns/{core,php,php-laravel}` (NOT the `-vendor` publish path),
 * plus any custom paths (incl. auto-discovered `.codeguard/patterns`).
 *
 * Applies the loader discriminator: a real pattern has both `verification.rules`
 * and `examples` — the corpus outliers (`preset.yaml`, `module.yaml`) are skipped.
 */
final class YamlPatternLoader implements PatternRepository
{
    /** @var list<Pattern>|null */
    private ?array $allCache = null;

    /**
     * @param  list<string>  $customPaths
     */
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly string $packagePatternsPath,
        private readonly array $customPaths = [],
    ) {}

    public function forPresets(array $presets): array
    {
        $patterns = [];

        foreach ($presets as $preset) {
            foreach ($this->loadDir($this->packagePatternsPath.DIRECTORY_SEPARATOR.$preset) as $pattern) {
                $patterns[] = $pattern;
            }
        }

        foreach ($this->customPaths as $path) {
            foreach ($this->loadDir($path) as $pattern) {
                $patterns[] = $pattern;
            }
        }

        return $patterns;
    }

    public function has(string $key): bool
    {
        foreach ($this->all() as $pattern) {
            if ($pattern->key === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<Pattern>
     */
    private function all(): array
    {
        if ($this->allCache !== null) {
            return $this->allCache;
        }

        $patterns = $this->loadDir($this->packagePatternsPath);
        foreach ($this->customPaths as $path) {
            foreach ($this->loadDir($path) as $pattern) {
                $patterns[] = $pattern;
            }
        }

        return $this->allCache = $patterns;
    }

    /**
     * @return list<Pattern>
     */
    private function loadDir(string $dir): array
    {
        if (! $this->filesystem->isDirectory($dir)) {
            return [];
        }

        $patterns = [];
        $finder = Finder::create()->files()->in($dir)->name('*.yaml')->sortByName();

        foreach ($finder as $file) {
            $parsed = Yaml::parseFile($file->getPathname());
            if (! is_array($parsed) || ! $this->isPattern($parsed)) {
                continue;
            }

            $patterns[] = Pattern::fromArray($file->getBasename('.yaml'), $parsed);
        }

        return $patterns;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function isPattern(array $data): bool
    {
        $verification = $data['verification'] ?? null;
        $examples = $data['examples'] ?? null;

        return is_array($verification)
            && is_array($verification['rules'] ?? null)
            && ($verification['rules'] !== [])
            && is_array($examples);
    }
}
