<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Analyze;

/**
 * Single source of truth for the finding contract: the JSON schema sent to
 * constrain the LLM call (Increment D) AND the shape {@see PatternMatch::fromArray}
 * validates — so request and validation cannot drift.
 */
final class FindingSchema
{
    public const KEY_PATTERN = 'pattern_key';

    public const KEY_FILE = 'file';

    public const KEY_LINE = 'line';

    public const KEY_MESSAGE = 'message';

    public const KEY_SEVERITY = 'severity';

    public const KEY_CONFIDENCE = 'confidence';

    /** Optional critique-pass re-score, 0–10. A 0 drops the finding (R2). */
    public const KEY_VERIFIED_SCORE = 'verified_score';

    /** Optional: the other end of a bad dependency, for architectural findings (R3). */
    public const KEY_RELATED_FILE = 'related_file';

    /**
     * JSON schema for the array of findings the LLM must return.
     *
     * @return array<string, mixed>
     */
    public static function jsonSchema(): array
    {
        return [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'required' => [
                    self::KEY_PATTERN,
                    self::KEY_FILE,
                    self::KEY_LINE,
                    self::KEY_MESSAGE,
                    self::KEY_SEVERITY,
                    self::KEY_CONFIDENCE,
                ],
                'properties' => [
                    self::KEY_PATTERN => ['type' => 'string'],
                    self::KEY_FILE => ['type' => 'string'],
                    self::KEY_LINE => ['type' => 'integer'],
                    self::KEY_MESSAGE => ['type' => 'string'],
                    self::KEY_SEVERITY => ['type' => 'string', 'enum' => ['critical', 'warning', 'suggestion']],
                    self::KEY_CONFIDENCE => ['type' => 'number'],
                    // Optional: set only by a critique pass. 0 ⇒ the finding is dropped.
                    self::KEY_VERIFIED_SCORE => ['type' => 'integer', 'minimum' => 0, 'maximum' => 10],
                    // Optional: the other end of a bad dependency (architectural findings).
                    self::KEY_RELATED_FILE => ['type' => 'string'],
                ],
            ],
        ];
    }
}
