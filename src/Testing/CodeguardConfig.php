<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Testing;

final readonly class CodeguardConfig
{
    /**
     * @param  array<string, GateConfig>  $gates
     * @param  array<string, StageConfig>  $stages
     * @param  list<string>  $protectedConfigs
     * @param  list<string>  $enabledPresets
     * @param  list<string>  $customPatternPaths
     * @param  list<string>  $contractorPatternKeys  empty = use built-in AnalyzeOptions::CONTRACTOR_KEYS
     * @param  array<string, bool>  $aiRulesTargets
     */
    public function __construct(
        public string $mode,
        public Preset $preset,
        public array $gates,
        public array $stages,
        public string $reportDir,
        public PrepareConfig $prepare,
        public array $protectedConfigs,
        public array $enabledPresets,
        public array $customPatternPaths,
        public string $baselinePath,
        public array $aiRulesTargets,
        public string $patternsFocus = 'full',
        public ?int $minCritiqueScore = null,
        public array $contractorPatternKeys = [],
        public bool $includeHygiene = false,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        /** @var array<string, array<string, mixed>> $gatesRaw */
        $gatesRaw = $config['gates'] ?? [];
        $gates = [];
        foreach ($gatesRaw as $key => $data) {
            $gates[$key] = GateConfig::fromArray($key, $data);
        }

        /** @var array<string, array<string, mixed>> $stagesRaw */
        $stagesRaw = $config['stages'] ?? [];
        $stages = [];
        foreach ($stagesRaw as $key => $data) {
            $stages[$key] = StageConfig::fromArray($key, $data);
        }

        $presetValue = (string) ($config['preset'] ?? Preset::Default->value);
        $preset = Preset::tryFrom($presetValue) ?? Preset::Default;

        /** @var array<string, mixed> $prepareRaw */
        $prepareRaw = $config['prepare'] ?? [];

        /** @var array<string, mixed> $patternsRaw */
        $patternsRaw = $config['patterns'] ?? [];

        /** @var array<string, mixed> $aiRulesRaw */
        $aiRulesRaw = $config['ai_rules'] ?? [];

        /** @var array<string, bool> $aiRulesTargets */
        $aiRulesTargets = $aiRulesRaw['targets'] ?? [];

        /** @var list<string> $protectedConfigs */
        $protectedConfigs = array_values((array) ($config['protected_configs'] ?? []));

        /** @var list<string> $enabledPresets */
        $enabledPresets = array_values((array) ($patternsRaw['enabled_presets'] ?? []));

        /** @var list<string> $customPatternPaths */
        $customPatternPaths = array_values((array) ($patternsRaw['custom_paths'] ?? []));

        $focus = strtolower((string) ($patternsRaw['focus'] ?? 'full'));
        if ($focus !== 'contractor') {
            $focus = 'full';
        }

        $minCritiqueRaw = $patternsRaw['min_critique_score'] ?? null;
        $minCritiqueScore = is_numeric($minCritiqueRaw) ? (int) $minCritiqueRaw : null;

        /** @var list<string> $contractorKeys */
        $contractorKeys = array_values(array_filter(
            array_map(
                static fn (mixed $key): string => is_scalar($key) ? (string) $key : '',
                (array) ($patternsRaw['contractor_keys'] ?? []),
            ),
            static fn (string $key): bool => $key !== '',
        ));

        $includeHygiene = filter_var($patternsRaw['include_hygiene'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return new self(
            mode: (string) ($config['mode'] ?? 'default'),
            preset: $preset,
            gates: $gates,
            stages: $stages,
            reportDir: (string) ($config['report_dir'] ?? ''),
            prepare: PrepareConfig::fromArray($prepareRaw),
            protectedConfigs: $protectedConfigs,
            enabledPresets: $enabledPresets,
            customPatternPaths: $customPatternPaths,
            baselinePath: (string) ($patternsRaw['baseline_path'] ?? ''),
            aiRulesTargets: $aiRulesTargets,
            patternsFocus: $focus,
            minCritiqueScore: $minCritiqueScore,
            contractorPatternKeys: $contractorKeys,
            includeHygiene: $includeHygiene,
        );
    }

    /**
     * @return list<GateConfig>
     */
    public function enabledGates(): array
    {
        return array_values(array_filter(
            $this->gates,
            static fn (GateConfig $gate): bool => $gate->enabled,
        ));
    }

    /**
     * @return list<StageConfig>
     */
    public function enabledStages(): array
    {
        return array_values(array_filter(
            $this->stages,
            static fn (StageConfig $stage): bool => $stage->enabled,
        ));
    }
}
