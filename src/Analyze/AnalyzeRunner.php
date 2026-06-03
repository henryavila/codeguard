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
     * @return array{system_prompt: string, finding_schema: array<string, mixed>, units: list<array<string, mixed>>}
     */
    public function buildWorkOrder(array $files, array $presets): array
    {
        $units = array_map(
            static fn (AnalysisUnit $unit): array => [
                'file' => $unit->file,
                'patterns' => array_map(
                    static fn (Pattern $pattern): array => $pattern->toPromptArray(),
                    $unit->patterns,
                ),
            ],
            $this->units($files, $presets),
        );

        return [
            'system_prompt' => $this->systemPrompt(),
            'finding_schema' => FindingSchema::jsonSchema(),
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

        $units = $this->units($files, $presets);
        $checks = array_sum(array_map(static fn (AnalysisUnit $unit): int => count($unit->patterns), $units));

        $matches = [];
        foreach ($rawFindings as $raw) {
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

        return $this->finish($matches, $checks, $start, $failOn, adjudicated: true);
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
     * @param  list<AnalysisUnit>  $units
     */
    private function findUnit(array $units, string $file): ?AnalysisUnit
    {
        foreach ($units as $unit) {
            if ($unit->file === $file || basename($unit->file) === basename($file)) {
                return $unit;
            }
        }

        return null;
    }

    /**
     * @param  list<PatternMatch>  $matches
     */
    private function finish(array $matches, int $checks, float $start, ?Severity $failOn, bool $adjudicated): AnalyzeResult
    {
        $durationMs = (int) round((hrtime(true) - $start) / 1_000_000);

        $result = new AnalyzeResult(
            patternsChecked: $checks,
            matches: $matches,
            durationMs: $durationMs,
            adjudicated: $adjudicated,
        );

        $this->recorder->record(
            event: EventName::AnalyzeEnded,
            status: $this->status($result, $failOn),
            durationMs: $durationMs,
            extras: [
                'patterns_checked_count' => $checks,
                'matches_count' => count($matches),
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
