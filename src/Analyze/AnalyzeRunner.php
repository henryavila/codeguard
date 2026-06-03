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
    public function run(array $files, array $presets, ?Severity $failOn, string $context): AnalyzeResult
    {
        $start = hrtime(true);

        $units = $this->units($files, $presets);
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

                    $match = PatternMatch::fromArray($raw, $unit, $this->patterns);
                    if ($match !== null) {
                        $matches[] = $match;
                    }
                }
            }
        }

        return $this->finish($matches, $checks, $start, $failOn, $adjudicated);
    }

    /**
     * Serialize the scoped units + patterns into a work order for a Claude Code
     * skill to review (one subagent per batch of files). No LLM is called here.
     *
     * @param  list<string>  $files
     * @param  list<string>  $presets
     * @param  int  $samples  How many independent review passes the skill should run (R1 voting).
     * @param  bool  $critique  Whether the skill should run a critique re-scoring pass (R2).
     * @return array{system_prompt: string, finding_schema: array<string, mixed>, samples: int, critique: bool, graph: array<string, mixed>, architecture: array{patterns: list<array<string, mixed>>}, units: list<array<string, mixed>>}
     */
    public function buildWorkOrder(array $files, array $presets, int $samples = 1, bool $critique = false): array
    {
        $patterns = $this->patterns->forPresets($presets);

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

        return [
            'system_prompt' => $this->systemPrompt(),
            'finding_schema' => FindingSchema::jsonSchema(),
            'samples' => max(1, $samples),
            'critique' => $critique,
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
    public function ingest(array $files, array $presets, array $rawFindings, ?Severity $failOn): AnalyzeResult
    {
        $start = hrtime(true);

        $patterns = $this->patterns->forPresets($presets);
        $perFileUnits = $this->matcher->match($files, $patterns);
        $checks = $this->checkCount($perFileUnits);
        $units = $this->withArchitecturalUnits($files, $patterns, $perFileUnits);
        $matches = $this->validate($units, $rawFindings);

        return $this->finish($matches, $checks, $start, $failOn, adjudicated: true);
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
    public function ingestSamples(array $files, array $presets, array $sampleSets, ?Severity $failOn, int $minVotes): AnalyzeResult
    {
        $start = hrtime(true);

        $patterns = $this->patterns->forPresets($presets);
        $perFileUnits = $this->matcher->match($files, $patterns);
        $checks = $this->checkCount($perFileUnits);
        $units = $this->withArchitecturalUnits($files, $patterns, $perFileUnits);

        $validatedSamples = array_map(
            fn (array $rawFindings): array => $this->validate($units, $rawFindings),
            $sampleSets,
        );

        $matches = (new FindingVoter)->tally($validatedSamples, $minVotes);

        return $this->finish($matches, $checks, $start, $failOn, adjudicated: true);
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
     * @return list<PatternMatch>
     */
    private function validate(array $units, array $rawFindings): array
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

            $match = PatternMatch::fromArray($raw, $unit, $this->patterns);
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
     * R2 critique drop: a finding the critique pass scored 0 is rejected. A null
     * score means uncritiqued (kept); any positive score is kept. Applied
     * uniformly to every path (synchronous, ingest, voted ingest).
     *
     * @param  list<PatternMatch>  $matches
     * @return list<PatternMatch>
     */
    private function surviveCritique(array $matches): array
    {
        return array_values(array_filter(
            $matches,
            static fn (PatternMatch $match): bool => $match->verifiedScore !== 0,
        ));
    }

    /**
     * @param  list<string>  $files
     * @param  list<string>  $presets
     * @return list<AnalysisUnit>
     */
    private function units(array $files, array $presets): array
    {
        return $this->matcher->match($files, $this->patterns->forPresets($presets));
    }

    /**
     * Attribute a finding to its unit. Exact absolute-path first (the subagent
     * echoes the path it was given); basename only as a fallback AND only when
     * unambiguous — otherwise two `User.php` in different dirs would silently
     * cross-attribute, which reads as a hallucination and poisons trust.
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

        $base = basename($file);
        $candidates = array_values(array_filter(
            $units,
            static fn (AnalysisUnit $unit): bool => basename($unit->file) === $base,
        ));

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    /**
     * @param  list<PatternMatch>  $matches
     */
    private function finish(array $matches, int $checks, float $start, ?Severity $failOn, bool $adjudicated): AnalyzeResult
    {
        $fresh = [];
        $suppressed = 0;
        foreach ($this->surviveCritique($matches) as $match) {
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
