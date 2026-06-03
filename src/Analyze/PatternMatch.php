<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Analyze;

/**
 * A validated finding. {@see fromArray()} is the trust boundary: it drops any
 * raw LLM finding that is malformed or fails the anti-hallucination checks for
 * the unit it came from.
 */
final readonly class PatternMatch
{
    public function __construct(
        public string $patternKey,
        public string $file,
        public int $line,
        public string $message,
        public Severity $severity,
        public float $confidence,
    ) {}

    /**
     * Immutable copy with a different confidence. Used by {@see FindingVoter} to
     * overwrite the model's self-reported confidence with the calibrated
     * vote-share across samples.
     */
    public function withConfidence(float $confidence): self
    {
        return new self(
            $this->patternKey,
            $this->file,
            $this->line,
            $this->message,
            $this->severity,
            $confidence,
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw, AnalysisUnit $unit, PatternRepository $patterns): ?self
    {
        $key = $raw[FindingSchema::KEY_PATTERN] ?? null;
        $file = $raw[FindingSchema::KEY_FILE] ?? null;
        $line = $raw[FindingSchema::KEY_LINE] ?? null;
        $message = $raw[FindingSchema::KEY_MESSAGE] ?? null;
        $severityRaw = $raw[FindingSchema::KEY_SEVERITY] ?? null;
        $confidence = $raw[FindingSchema::KEY_CONFIDENCE] ?? null;

        if (! is_string($key) || ! is_string($file) || ! is_string($message) || ! is_string($severityRaw)) {
            return null;
        }

        if (! is_numeric($line) || ! is_numeric($confidence)) {
            return null;
        }

        $severity = Severity::tryFrom($severityRaw);
        if ($severity === null) {
            return null;
        }

        $confidenceValue = (float) $confidence;
        if ($confidenceValue < 0.0 || $confidenceValue > 1.0) {
            return null;
        }

        // patternKey must be one dispatched for this unit, or a real corpus key.
        if (! in_array($key, $unit->patternKeys(), true) && ! $patterns->has($key)) {
            return null;
        }

        // The finding must point at the file we actually analyzed.
        if (basename($file) !== basename($unit->file)) {
            return null;
        }

        return new self(
            patternKey: $key,
            file: $unit->file,
            line: max(1, (int) $line),
            message: $message,
            severity: $severity,
            confidence: $confidenceValue,
        );
    }
}
