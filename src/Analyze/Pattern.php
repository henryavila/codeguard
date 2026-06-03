<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Analyze;

/**
 * An immutable curated review pattern loaded from a `resources/patterns/*.yaml`.
 *
 * Field roles (see docs/specs/2026-06-03-patterns-engine-design.md §2):
 *  - LLM prompt: description, verificationRules, examplesCorrect/Violation, severity
 *  - Pre-filter (never sent to LLM): detectionSignals
 *  - Metadata: name, category, layer, classification, confidence, relatedPatterns
 */
final readonly class Pattern
{
    /**
     * @param  list<DetectionSignal>  $detectionSignals
     * @param  list<string>  $verificationRules
     * @param  list<string>  $relatedPatterns
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $description,
        public string $category,
        public string $layer,
        public Severity $severity,
        public string $classification,
        public array $detectionSignals,
        public string $confidence,
        public array $verificationRules,
        public string $examplesCorrect,
        public string $examplesViolation,
        public array $relatedPatterns,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(string $key, array $data): self
    {
        $detection = self::asArray($data['detection'] ?? null);

        $signals = [];
        foreach (self::asArray($detection['signals'] ?? null) as $signal) {
            $signal = self::asArray($signal);
            if (isset($signal['type'], $signal['value'])) {
                $signals[] = new DetectionSignal((string) $signal['type'], (string) $signal['value']);
            }
        }

        $verification = self::asArray($data['verification'] ?? null);
        $rules = array_values(array_map(
            static fn (mixed $rule): string => (string) (is_scalar($rule) ? $rule : ''),
            self::asArray($verification['rules'] ?? null),
        ));

        $examples = self::asArray($data['examples'] ?? null);
        $related = array_values(array_map(
            static fn (mixed $name): string => (string) (is_scalar($name) ? $name : ''),
            self::asArray($data['related_patterns'] ?? null),
        ));

        return new self(
            key: $key,
            name: (string) ($data['name'] ?? $key),
            description: (string) ($data['description'] ?? ''),
            category: (string) ($data['category'] ?? ''),
            layer: (string) ($data['layer'] ?? ''),
            severity: Severity::tryFrom((string) ($data['severity'] ?? '')) ?? Severity::Warning,
            classification: (string) ($data['classification'] ?? 'mvp'),
            detectionSignals: $signals,
            confidence: (string) ($detection['confidence'] ?? 'medium'),
            verificationRules: $rules,
            examplesCorrect: (string) ($examples['correct'] ?? ''),
            examplesViolation: (string) ($examples['violation'] ?? ''),
            relatedPatterns: $related,
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function asArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
