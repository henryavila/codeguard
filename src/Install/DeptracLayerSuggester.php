<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Install;

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

final class DeptracLayerSuggester
{
    /**
     * Namespace keyword → layer mapping.
     *
     * Matching is case-insensitive. Exact match wins over substring match.
     *
     * Layers:
     *   Domain         → pure business rules, value objects, policies
     *   Application    → services, jobs, events, listeners, notifications
     *   Presentation   → HTTP, Livewire, Filament, Nova, Console, Views
     *   Infrastructure → Eloquent models, repositories, external adapters
     *   __skip__       → bootstrap/cross-cutting (Providers, Traits, …)
     */
    private const LAYER_HEURISTICS = [
        'Domain' => [
            'Domain', 'Entities', 'Entity',
            'ValueObjects', 'ValueObject',
            'Policies', 'Policy',
            'Rules', 'Rule',
            'Enums', 'Enum',
            'Contracts', 'Contract',
            'Interfaces', 'Interface',
            'Abstracts', 'Abstract',
        ],
        'Application' => [
            'Services', 'Service',
            'UseCases', 'UseCase',
            'Actions', 'Action',
            'Jobs', 'Job',
            'Events', 'Event',
            'Listeners', 'Listener',
            'Observers', 'Observer',
            'Notifications', 'Notification',
            'Mail', 'Mails', 'Mailables',
            'Pipes', 'Pipe',
            'Features', 'Feature',
            'DTOs', 'DTO', 'Dto', 'Dtos',
            'Exports', 'Export',
            'Commands', 'Queries',
        ],
        'Presentation' => [
            'Http', 'Controllers', 'Controller',
            'Livewire', 'Filament', 'Nova',
            'Console',
            'Forms', 'Form',
            'View', 'Views',
            'Presenters', 'Presenter',
        ],
        'Infrastructure' => [
            'Models', 'Model',
            'Repositories', 'Repository',
            'Eloquent', 'Infrastructure',
            'ExternalApi', 'ExternalApis',
            'Integrations', 'Integration',
            'Adapters', 'Adapter',
            'Configurators', 'Configurator',
            'Upgrades', 'Upgrade',
            'Overwrites', 'Overwrite',
        ],
        '__skip__' => [
            'Providers', 'Provider',
            'Exceptions', 'Exception',
            'Traits', 'Trait',
            'Helpers', 'Helper',
            'Objects', 'Object',
        ],
    ];

    private const DEFAULT_RULESET = [
        'Domain' => [],
        'Application' => ['Domain'],
        'Infrastructure' => ['Domain'],
        'Presentation' => ['Application', 'Domain'],
    ];

    private const BUILT_IN_LAYERS = ['Domain', 'Application', 'Presentation', 'Infrastructure'];

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

    /**
     * Apply wizard decisions to produce an enriched LayerSuggestion including
     * any custom layers the user introduced. Built-in layer defaults
     * (Application → Domain, etc.) are preserved; custom layers start with
     * no outbound dependencies and can be tightened by editing deptrac.yaml.
     */
    public function withDecisions(LayerSuggestion $original, WizardResult $result): LayerSuggestion
    {
        if ($result->isEmpty()) {
            return $original;
        }

        $groupedNamespaces = $this->groupDecisionsByLayer($original, $result);
        $layers = $this->buildLayersFromGroups($groupedNamespaces);
        $ruleset = $this->buildRulesetForLayers(
            array_keys($layers),
            $result->customLayers,
        );

        return new LayerSuggestion(
            detectedNamespaces: $original->detectedNamespaces,
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
                    // Deptrac 2.x reads $config['value'] for classLike — error
                    // message says "needs the regex configuration" but the
                    // actual key is 'value'. See vendor source:
                    // deptrac/deptrac/src/Core/Layer/Collector/AbstractTypeCollector.php
                    'value' => $pattern,
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
        $finder = new Finder;
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
        $finder = new Finder;
        $finder->files()->in($directory)->name('*.php');

        return $finder->count();
    }

    /**
     * Case-insensitive match. Exact match is prioritised so that `Providers`
     * maps to `__skip__` even if a keyword `Provider` is somehow reused in
     * another layer. Falls back to substring match for compound names.
     *
     * Returns:
     *   - a layer name (Domain/Application/Presentation/Infrastructure)
     *   - LayerOption::Skip->value (`__skip__`) for skip-suggested namespaces
     *   - null for unclassified (wizard will ask the user)
     */
    private function mapToLayer(string $namespaceName): ?string
    {
        $lowered = strtolower($namespaceName);

        foreach (self::LAYER_HEURISTICS as $layer => $keywords) {
            foreach ($keywords as $keyword) {
                if ($lowered === strtolower($keyword)) {
                    return $layer;
                }
            }
        }

        foreach (self::LAYER_HEURISTICS as $layer => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($lowered, strtolower($keyword))) {
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

            if ($namespace->suggestedLayer === LayerOption::Skip->value) {
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
     * @return array<string, list<string>> layerName => list of escaped namespace regexes
     */
    private function groupDecisionsByLayer(LayerSuggestion $original, WizardResult $result): array
    {
        $grouped = [];

        foreach ($original->detectedNamespaces as $namespace) {
            if ($namespace->suggestedLayer === null) {
                continue;
            }

            if ($namespace->suggestedLayer === LayerOption::Skip->value) {
                continue;
            }

            $grouped[$namespace->suggestedLayer][] = $this->escapeRegex($namespace->namespace);
        }

        foreach ($result->decisions as $decision) {
            if ($decision->layerName === null) {
                continue;
            }

            $grouped[$decision->layerName][] = $this->escapeRegex($decision->namespace);
        }

        return $grouped;
    }

    /**
     * @param  array<string, list<string>>  $grouped
     * @return array<string, list<string>>
     */
    private function buildLayersFromGroups(array $grouped): array
    {
        $layers = [];

        foreach ($grouped as $layerName => $escapedNamespaces) {
            if ($escapedNamespaces === []) {
                continue;
            }

            $unique = array_values(array_unique($escapedNamespaces));

            $alternation = count($unique) === 1
                ? $unique[0]
                : '('.implode('|', $unique).')';

            $layers[$layerName] = ['^'.$alternation.'\\\\.*'];
        }

        return $layers;
    }

    /**
     * @param  list<string>  $activeLayers
     * @param  list<string>  $customLayers
     * @return array<string, list<string>>
     */
    private function buildRulesetForLayers(array $activeLayers, array $customLayers): array
    {
        $ruleset = [];

        foreach (self::DEFAULT_RULESET as $layer => $allowed) {
            if (! in_array($layer, $activeLayers, strict: true)) {
                continue;
            }

            $ruleset[$layer] = array_values(array_filter(
                $allowed,
                static fn (string $other): bool => in_array($other, $activeLayers, strict: true),
            ));
        }

        foreach ($customLayers as $customLayer) {
            if (! in_array($customLayer, $activeLayers, strict: true)) {
                continue;
            }

            if (! isset($ruleset[$customLayer])) {
                $ruleset[$customLayer] = [];
            }
        }

        return $ruleset;
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

    /**
     * Exposes built-in layer names for callers that need to check
     * (wizard uses this to detect custom-layer names).
     *
     * @return list<string>
     */
    public static function builtInLayers(): array
    {
        return self::BUILT_IN_LAYERS;
    }
}
