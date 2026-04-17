<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Install;

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

final class DeptracLayerSuggester
{
    private const LAYER_HEURISTICS = [
        'Domain' => ['Domain', 'Entities', 'Policies', 'ValueObjects'],
        'Application' => ['Services', 'Http', 'Controllers', 'Console', 'Jobs', 'Actions', 'UseCases'],
        'Persistence' => ['Models', 'Infrastructure', 'Repositories', 'Eloquent'],
    ];

    private const DEFAULT_RULESET = [
        'Domain' => [],
        'Application' => ['Domain'],
        'Persistence' => ['Domain'],
    ];

    public function __construct(
        private readonly Filesystem $filesystem,
    ) {}

    public function suggest(string $appPath): LayerSuggestion
    {
        if (! $this->filesystem->isDirectory($appPath)) {
            return new LayerSuggestion(
                detectedNamespaces: [],
                layers: [],
                ruleset: [],
            );
        }

        $detected = $this->scanAppDirectory($appPath);
        $layers = $this->buildLayerRegexes($detected);
        $ruleset = $this->filterRuleset(array_keys($layers));

        return new LayerSuggestion(
            detectedNamespaces: $detected,
            layers: $layers,
            ruleset: $ruleset,
        );
    }

    public function toYaml(LayerSuggestion $suggestion): string
    {
        if ($suggestion->isEmpty()) {
            return "deptrac:\n    paths:\n        - ./app\n    layers: []\n    ruleset: []\n";
        }

        $layers = [];
        foreach ($suggestion->layers as $name => $patterns) {
            $collectors = [];
            foreach ($patterns as $pattern) {
                $collectors[] = [
                    'type' => 'classLike',
                    'regex' => $pattern,
                ];
            }

            $layers[] = [
                'name' => $name,
                'collectors' => $collectors,
            ];
        }

        $ruleset = [];
        foreach ($suggestion->ruleset as $layerName => $allowed) {
            $ruleset[$layerName] = $allowed === [] ? null : $allowed;
        }

        $document = [
            'deptrac' => [
                'paths' => ['./app'],
                'exclude_files' => ['#.*test.*#i'],
                'layers' => $layers,
                'ruleset' => $ruleset,
            ],
        ];

        return Yaml::dump(
            input: $document,
            inline: 6,
            indent: 4,
            flags: Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE,
        );
    }

    /**
     * @return list<DetectedNamespace>
     */
    private function scanAppDirectory(string $appPath): array
    {
        $finder = new Finder();
        $finder->directories()->in($appPath)->depth('== 0');

        $detected = [];

        foreach ($finder as $directory) {
            $name = $directory->getFilename();
            $fileCount = $this->countPhpFiles($directory->getPathname());

            if ($fileCount === 0) {
                continue;
            }

            $detected[] = new DetectedNamespace(
                relativePath: 'app/'.$name,
                namespace: 'App\\'.$name,
                fileCount: $fileCount,
                suggestedLayer: $this->mapToLayer($name),
            );
        }

        usort(
            $detected,
            static fn (DetectedNamespace $a, DetectedNamespace $b): int => $b->fileCount <=> $a->fileCount,
        );

        return $detected;
    }

    private function countPhpFiles(string $directory): int
    {
        $finder = new Finder();
        $finder->files()->in($directory)->name('*.php');

        return $finder->count();
    }

    private function mapToLayer(string $namespaceName): ?string
    {
        foreach (self::LAYER_HEURISTICS as $layer => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($namespaceName, $keyword)) {
                    return $layer;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<DetectedNamespace>  $detected
     * @return array<string, list<string>>
     */
    private function buildLayerRegexes(array $detected): array
    {
        $grouped = [];

        foreach ($detected as $namespace) {
            if ($namespace->suggestedLayer === null) {
                continue;
            }

            $grouped[$namespace->suggestedLayer][] = $this->escapeRegex($namespace->namespace);
        }

        $layers = [];
        foreach ($grouped as $layer => $escapedNamespaces) {
            $alternation = count($escapedNamespaces) === 1
                ? $escapedNamespaces[0]
                : '('.implode('|', $escapedNamespaces).')';

            $layers[$layer] = ['^'.$alternation.'\\\\.*'];
        }

        return $layers;
    }

    private function escapeRegex(string $namespace): string
    {
        return str_replace('\\', '\\\\', $namespace);
    }

    /**
     * @param  list<string>  $activeLayers
     * @return array<string, list<string>>
     */
    private function filterRuleset(array $activeLayers): array
    {
        $ruleset = [];

        foreach (self::DEFAULT_RULESET as $layer => $allowed) {
            if (! in_array($layer, $activeLayers, strict: true)) {
                continue;
            }

            $filteredAllowed = array_values(array_filter(
                $allowed,
                static fn (string $other): bool => in_array($other, $activeLayers, strict: true),
            ));

            $ruleset[$layer] = $filteredAllowed;
        }

        return $ruleset;
    }
}
