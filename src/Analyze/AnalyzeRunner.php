<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Analyze;

use Henryavila\Codeguard\Telemetry\EventName;
use Henryavila\Codeguard\Telemetry\EventStatus;
use Henryavila\Codeguard\Telemetry\Recorder;

/**
 * Orchestrates an analyze run. Three entry points share the same deterministic
 * scope+match core ({@see units()}):
 *
 *  - run()            — synchronous: call the {@see LlmClient} per file (used by
 *                       a future API driver; NullLlmClient yields the honest
 *                       no-driver degradation path).
 *  - buildWorkOrder() — context-emit: serialize units for a Claude Code skill to
 *                       review with subagents (subscription, no external API).
 *  - ingest()         — validate findings produced out-of-band through the same
 *                       {@see PatternMatch} trust boundary, then gate + telemeter.
 *
 * Findings are validated identically regardless of who produced them.
 */
final class AnalyzeRunner
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly PatternRepository $patterns,
        private readonly PatternMatcher $matcher,
        private readonly LlmClient $llm,
        private readonly AnalyzeBaseline $baseline,
        private readonly string $systemPromptPath,
    ) {}

    /**
     * @param  list<string>  $files  Absolute paths (already scoped by the command).
     * @param  list<string>  $presets
     */
    public function run(
        array $files,
        array $presets,
        ?Severity $failOn,
        string $context,
        ?AnalyzeOptions $options = null,
    ): AnalyzeResult {
        $options ??= AnalyzeOptions::full();
        $start = hrtime(true);

        $patterns = $this->resolvePatterns($presets, $options);
        $units = $this->matcher->match($files, $patterns);
        $graphKeys = $this->graphLevelKeys($patterns);
        $adjudicated = $this->llm->isConfigured();
        $matches = [];
        $checks = 0;

        if ($adjudicated) {
            $systemPrompt = $this->systemPrompt();

            foreach ($units as $unit) {
                $checks += count($unit->patterns);

                foreach ($this->llm->review($unit, $systemPrompt) as $raw) {
                    if (! is_array($raw)) {
                        continue;
                    }

                    $match = PatternMatch::fromArray($raw, $unit, $graphKeys);
                    if ($match !== null) {
                        $matches[] = $match;
                    }
                }
            }
        }

        return $this->finish($matches, $checks, $start, $failOn, $adjudicated, $options);
    }

    /**
     * Serialize the scoped units + patterns into a work order for a Claude Code
     * skill to review (one subagent per batch of files). No LLM is called here.
     *
     * @param  list<string>  $files
     * @param  list<string>  $presets
     * @param  int  $samples  How many independent review passes the skill should run (R1 voting).
     * @param  bool  $critique  Whether the skill should run a critique re-scoring pass (R2).
     * @param  array<string, mixed>|null  $scope  Resolved file scope metadata (mode, files, SHAs).
     * @return array{system_prompt: string, finding_schema: array<string, mixed>, samples: int, critique: bool, focus: string, min_critique_score: int, scope: array<string, mixed>, graph: array<string, mixed>, architecture: array{patterns: list<array<string, mixed>>}, units: list<array<string, mixed>>}
     */
    public function buildWorkOrder(
        array $files,
        array $presets,
        int $samples = 1,
        bool $critique = false,
        ?AnalyzeOptions $options = null,
        ?array $scope = null,
    ): array {
        $options ??= AnalyzeOptions::full();
        $patterns = $this->resolvePatterns($presets, $options);

        $units = array_map(
            static fn (AnalysisUnit $unit): array => [
                'file' => $unit->file,
                'patterns' => array_map(
                    static fn (Pattern $pattern): array => $pattern->toPromptArray(),
                    $unit->patterns,
                ),
            ],
            $this->matcher->match($files, $patterns),
        );

        $architecturePatterns = array_map(
            static fn (Pattern $pattern): array => $pattern->toPromptArray(),
            $this->matcher->graphLevel($patterns),
        );

        $resolvedScope = $scope ?? [
            'mode' => 'path',
            'base_ref' => null,
            'committed_only' => false,
            'path' => null,
            'head_sha' => null,
            'merge_base_sha' => null,
            'files' => array_values($files),
        ];
        if (! isset($resolvedScope['files']) || ! is_array($resolvedScope['files'])) {
            $resolvedScope['files'] = array_values($files);
        }

        return [
            'system_prompt' => $this->systemPrompt(),
            'finding_schema' => FindingSchema::jsonSchema(),
            'samples' => max(1, $samples),
            'critique' => $critique,
            'focus' => $options->onlyPatternKeys === null
                ? AnalyzeOptions::FOCUS_FULL
                : AnalyzeOptions::FOCUS_CONTRACTOR,
            'min_critique_score' => $options->minCritiqueScore,
            'scope' => $resolvedScope,
            'graph' => (new NamespaceGraph)->build($files, $this->matcher->workingDirectory()),
            'architecture' => ['patterns' => $architecturePatterns],
            'units' => $units,
        ];
    }

    /**
     * Validate findings produced out-of-band (by the Claude Code subagents)
     * against a fresh scope+match, through the same trust boundary.
     *
     * @param  list<string>  $files
     * @param  list<string>  $presets
     * @param  list<array<string, mixed>>  $rawFindings
     */
    public function ingest(
        array $files,
        array $presets,
        array $rawFindings,
        ?Severity $failOn,
        ?AnalyzeOptions $options = null,
    ): AnalyzeResult {
        $options ??= AnalyzeOptions::full();
        $start = hrtime(true);

        $patterns = $this->resolvePatterns($presets, $options);
        $perFileUnits = $this->matcher->match($files, $patterns);
        $checks = $this->checkCount($perFileUnits);
        $units = $this->withArchitecturalUnits($files, $patterns, $perFileUnits);
        $matches = $this->validate($units, $rawFindings, $this->graphLevelKeys($patterns));

        return $this->finish($matches, $checks, $start, $failOn, adjudicated: true, options: $options);
    }

    /**
     * R1 voting: validate each of the k samples through the trust boundary, then
     * keep only findings that ≥ $minVotes samples agree on, with confidence set
     * to the vote-share. Hallucinations are dropped per-sample BEFORE voting, so
     * a finding can never accrue a vote it was not entitled to.
     *
     * @param  list<string>  $files
     * @param  list<string>  $presets
     * @param  list<list<array<string, mixed>>>  $sampleSets  raw findings, one list per sample
     */
    public function ingestSamples(
        array $files,
        array $presets,
        array $sampleSets,
        ?Severity $failOn,
        int $minVotes,
        ?AnalyzeOptions $options = null,
    ): AnalyzeResult {
        $options ??= AnalyzeOptions::full();
        $start = hrtime(true);

        $patterns = $this->resolvePatterns($presets, $options);
        $perFileUnits = $this->matcher->match($files, $patterns);
        $checks = $this->checkCount($perFileUnits);
        $units = $this->withArchitecturalUnits($files, $patterns, $perFileUnits);
        $graphKeys = $this->graphLevelKeys($patterns);

        $validatedSamples = array_map(
            fn (array $rawFindings): array => $this->validate($units, $rawFindings, $graphKeys),
            $sampleSets,
        );

        $matches = (new FindingVoter)->tally($validatedSamples, $minVotes);

        return $this->finish($matches, $checks, $start, $failOn, adjudicated: true, options: $options);
    }

    /**
     * Augment the per-file units with one architectural unit per scoped class
     * file that matched no per-file pattern — so an architectural finding (whose
     * graph-level pattern is never selected per file) still attributes to a
     * real, in-scope file through the trust boundary. Attribution only; it does
     * not change the per-file check count.
     *
     * @param  list<string>  $files
     * @param  list<Pattern>  $patterns
     * @param  list<AnalysisUnit>  $perFileUnits
     * @return list<AnalysisUnit>
     */
    private function withArchitecturalUnits(array $files, array $patterns, array $perFileUnits): array
    {
        $graphLevel = $this->matcher->graphLevel($patterns);
        if ($graphLevel === []) {
            return $perFileUnits;
        }

        $covered = [];
        foreach ($perFileUnits as $unit) {
            $covered[$unit->file] = true;
        }

        $units = $perFileUnits;
        foreach ($files as $file) {
            if (isset($covered[$file])) {
                continue;
            }

            $contents = is_file($file) ? (file_get_contents($file) ?: '') : '';
            if (PhpFileInspector::fqcn($contents) === null) {
                continue;
            }

            $units[] = new AnalysisUnit($file, $graphLevel);
        }

        return $units;
    }

    /**
     * Validate a list of raw findings against the scoped units through the
     * {@see PatternMatch} trust boundary. Shared by {@see ingest()} and each
     * sample of {@see ingestSamples()}.
     *
     * @param  list<AnalysisUnit>  $units
     * @param  list<array<string, mixed>>  $rawFindings
     * @param  list<string>  $graphKeys  graph-level pattern keys admissible on any in-scope unit
     * @return list<PatternMatch>
     */
    private function validate(array $units, array $rawFindings, array $graphKeys): array
    {
        $matches = [];
        foreach ($rawFindings as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $file = $raw[FindingSchema::KEY_FILE] ?? null;
            if (! is_string($file)) {
                continue;
            }

            $unit = $this->findUnit($units, $file);
            if ($unit === null) {
                continue;
            }

            $match = PatternMatch::fromArray($raw, $unit, $graphKeys);
            if ($match !== null) {
                $matches[] = $match;
            }
        }

        return $matches;
    }

    /**
     * @param  list<AnalysisUnit>  $units
     */
    private function checkCount(array $units): int
    {
        return array_sum(array_map(static fn (AnalysisUnit $unit): int => count($unit->patterns), $units));
    }

    /**
     * R2 critique floor: drop findings whose verified_score is present and below
     * {@see AnalyzeOptions::$minCritiqueScore}. Null (uncritiqued) always keeps.
     * Default floor 1 = historical "drop only score 0". Contractor floor 4 also
     * drops soft scores 1–3 from field runs.
     *
     * @param  list<PatternMatch>  $matches
     * @return list<PatternMatch>
     */
    private function surviveCritique(array $matches, AnalyzeOptions $options): array
    {
        return array_values(array_filter(
            $matches,
            static fn (PatternMatch $match): bool => $options->critiqueSurvives($match->verifiedScore),
        ));
    }

    /**
     * @param  list<string>  $presets
     * @return list<Pattern>
     */
    private function resolvePatterns(array $presets, AnalyzeOptions $options): array
    {
        $patterns = $this->patterns->forPresets($presets);
        $allow = $options->onlyPatternKeys;
        if ($allow !== null) {
            $set = array_fill_keys($allow, true);
            $patterns = array_values(array_filter(
                $patterns,
                static fn (Pattern $pattern): bool => isset($set[$pattern->key]),
            ));
        }

        if ($options->excludeClassifications !== []) {
            $exclude = array_fill_keys($options->excludeClassifications, true);
            $patterns = array_values(array_filter(
                $patterns,
                static fn (Pattern $pattern): bool => ! isset($exclude[$pattern->classification]),
            ));
        }

        return $patterns;
    }

    /**
     * Attribute a finding to its unit. Exact absolute-path first (the per-file
     * subagent echoes the path it was given). An architectural finding instead
     * cites a working-dir-RELATIVE path (the namespace graph emits relative
     * node paths), so resolve that against the working directory and retry the
     * exact match before the basename fallback — otherwise two `User.php` in
     * different dirs make the basename ambiguous and the finding is dropped.
     * Basename is the last resort AND only when unambiguous, so a genuinely
     * unattributable finding still reads as a hallucination and is rejected.
     *
     * @param  list<AnalysisUnit>  $units
     */
    private function findUnit(array $units, string $file): ?AnalysisUnit
    {
        foreach ($units as $unit) {
            if ($unit->file === $file) {
                return $unit;
            }
        }

        $resolved = $this->resolveAgainstWorkingDirectory($file);
        if ($resolved !== $file) {
            foreach ($units as $unit) {
                if ($unit->file === $resolved) {
                    return $unit;
                }
            }
        }

        $base = basename($file);
        $candidates = array_values(array_filter(
            $units,
            static fn (AnalysisUnit $unit): bool => basename($unit->file) === $base,
        ));

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    /**
     * Resolve a possibly-relative finding path to an absolute path under the
     * scan's working directory. An already-absolute path (the per-file subagent
     * echoes the absolute path it was given) is returned unchanged.
     */
    private function resolveAgainstWorkingDirectory(string $file): string
    {
        if (str_starts_with($file, DIRECTORY_SEPARATOR)) {
            return $file;
        }

        $prefix = rtrim($this->matcher->workingDirectory(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return $prefix.ltrim(str_replace('\\', '/', $file), '/');
    }

    /**
     * Graph-level pattern keys for the selected patterns. These are dispatched
     * at graph scope (not per file), so the trust boundary admits them on any
     * in-scope unit in addition to that unit's own dispatched patterns.
     *
     * @param  list<Pattern>  $patterns
     * @return list<string>
     */
    private function graphLevelKeys(array $patterns): array
    {
        return array_map(
            static fn (Pattern $pattern): string => $pattern->key,
            $this->matcher->graphLevel($patterns),
        );
    }

    /**
     * @param  list<PatternMatch>  $matches
     */
    private function finish(
        array $matches,
        int $checks,
        float $start,
        ?Severity $failOn,
        bool $adjudicated,
        ?AnalyzeOptions $options = null,
    ): AnalyzeResult {
        $options ??= AnalyzeOptions::full();
        $fresh = [];
        $suppressed = 0;
        foreach ($this->surviveCritique($matches, $options) as $match) {
            if ($this->baseline->isAccepted($match)) {
                $suppressed++;

                continue;
            }
            $fresh[] = $match;
        }

        $durationMs = (int) round((hrtime(true) - $start) / 1_000_000);

        $result = new AnalyzeResult(
            patternsChecked: $checks,
            matches: $fresh,
            durationMs: $durationMs,
            adjudicated: $adjudicated,
            suppressedCount: $suppressed,
        );

        $this->recorder->record(
            event: EventName::AnalyzeEnded,
            status: $this->status($result, $failOn),
            durationMs: $durationMs,
            extras: [
                'patterns_checked_count' => $checks,
                'matches_count' => count($fresh),
            ],
        );

        return $result;
    }

    private function status(AnalyzeResult $result, ?Severity $failOn): EventStatus
    {
        if (! $result->adjudicated) {
            return EventStatus::Skip;
        }

        return $result->failed($failOn) ? EventStatus::Fail : EventStatus::Ok;
    }

    private function systemPrompt(): string
    {
        if (is_file($this->systemPromptPath)) {
            $contents = file_get_contents($this->systemPromptPath);
            if ($contents !== false) {
                return $contents;
            }
        }

        return 'You are a senior code reviewer. Return only real violations as a JSON array of findings.';
    }
}
