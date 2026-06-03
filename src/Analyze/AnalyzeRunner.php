<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Analyze;

use Henryavila\Codeguard\Telemetry\EventName;
use Henryavila\Codeguard\Telemetry\EventStatus;
use Henryavila\Codeguard\Telemetry\Recorder;

/**
 * Orchestrates an analyze run: load patterns for the presets, match them to
 * the scoped files, ask the {@see LlmClient} to review each unit, validate
 * findings through the {@see PatternMatch} trust boundary, and emit the
 * `analyze.ended` telemetry event.
 *
 * Makes ONE LLM call per file (the file is the expensive shared context).
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

        $patterns = $this->patterns->forPresets($presets);
        $units = $this->matcher->match($files, $patterns);

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
