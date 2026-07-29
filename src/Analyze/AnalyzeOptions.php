<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Analyze;

/**
 * Runtime knobs for an analyze run (emit / ingest / sync).
 *
 * Two product modes after the Arch field audit (2026-07-27):
 *  - {@see self::full()} — full corpus minus hygiene by default (types/dry/…).
 *    Pass includeHygiene to restore inventory-complete emit.
 *  - {@see self::contractor()} — G3 contractor-gate only: security/data smells
 *    that AST tools miss + architecture/service-layer leaks. Pairs with a
 *    higher critique floor so weak scores (2–3/10) do not survive.
 *
 * Critique floor: a finding with {@see PatternMatch::$verifiedScore} below
 * {@see $minCritiqueScore} is dropped. A null score means uncritiqued and is
 * always kept. Default floor of 1 preserves the historical "drop only score 0"
 * behaviour; contractor mode raises it to 4.
 */
final readonly class AnalyzeOptions
{
    public const FOCUS_FULL = 'full';

    public const FOCUS_CONTRACTOR = 'contractor';

    /** Drop only critique score 0 (legacy R2). */
    public const DEFAULT_MIN_CRITIQUE_SCORE = 1;

    /** Contractor-gate floor: soft scores (1–3) are dropped. */
    public const CONTRACTOR_MIN_CRITIQUE_SCORE = 4;

    /**
     * High-impact keys for G3 (contractor PR review). Security/data R4 + layer
     * graph patterns + service-layer HTTP leaks — the set that field-audited
     * as genuine and product-relevant.
     *
     * @var list<string>
     */
    public const CONTRACTOR_KEYS = [
        'mass-assignment',
        'raw-sql-injection',
        'missing-authorization',
        'eloquent-n-plus-one',
        'missing-database-transaction',
        'unbounded-query',
        'layer-dependency-direction',
        'bounded-contexts',
        'no-circular-dependencies',
        'service-layer',
    ];

    /**
     * @param  list<string>|null  $onlyPatternKeys  null = no key filter (full corpus)
     * @param  int  $minCritiqueScore  drop findings with verified_score in [0, min)
     * @param  list<string>  $excludeClassifications  e.g. ['hygiene'] — full default
     */
    public function __construct(
        public ?array $onlyPatternKeys = null,
        public int $minCritiqueScore = self::DEFAULT_MIN_CRITIQUE_SCORE,
        public array $excludeClassifications = [],
    ) {}

    public static function full(
        int $minCritiqueScore = self::DEFAULT_MIN_CRITIQUE_SCORE,
        bool $includeHygiene = false,
    ): self {
        return new self(
            onlyPatternKeys: null,
            minCritiqueScore: self::clampScore($minCritiqueScore),
            excludeClassifications: $includeHygiene ? [] : ['hygiene'],
        );
    }

    public static function contractor(int $minCritiqueScore = self::CONTRACTOR_MIN_CRITIQUE_SCORE): self
    {
        return new self(
            onlyPatternKeys: self::CONTRACTOR_KEYS,
            minCritiqueScore: self::clampScore($minCritiqueScore),
            excludeClassifications: [],
        );
    }

    /**
     * Resolve CLI/config focus + optional overrides.
     *
     * @param  list<string>|null  $onlyPatternKeys  explicit allowlist wins over focus
     * @param  list<string>|null  $contractorKeys  optional override of the built-in G3 set
     */
    public static function resolve(
        string $focus = self::FOCUS_FULL,
        ?int $minCritiqueScore = null,
        ?array $onlyPatternKeys = null,
        ?array $contractorKeys = null,
        bool $includeHygiene = false,
    ): self {
        $focus = strtolower(trim($focus));
        if ($focus !== self::FOCUS_CONTRACTOR) {
            $focus = self::FOCUS_FULL;
        }

        $normalizedOnly = self::normalizeKeys($onlyPatternKeys);
        if ($normalizedOnly !== null) {
            $floor = $minCritiqueScore ?? (
                $focus === self::FOCUS_CONTRACTOR
                    ? self::CONTRACTOR_MIN_CRITIQUE_SCORE
                    : self::DEFAULT_MIN_CRITIQUE_SCORE
            );

            return new self(
                onlyPatternKeys: $normalizedOnly,
                minCritiqueScore: self::clampScore($floor),
                excludeClassifications: [],
            );
        }

        if ($focus === self::FOCUS_CONTRACTOR) {
            $keys = self::normalizeKeys($contractorKeys) ?? self::CONTRACTOR_KEYS;

            return new self(
                onlyPatternKeys: $keys,
                minCritiqueScore: self::clampScore(
                    $minCritiqueScore ?? self::CONTRACTOR_MIN_CRITIQUE_SCORE,
                ),
                excludeClassifications: [],
            );
        }

        return new self(
            onlyPatternKeys: null,
            minCritiqueScore: self::clampScore(
                $minCritiqueScore ?? self::DEFAULT_MIN_CRITIQUE_SCORE,
            ),
            excludeClassifications: $includeHygiene ? [] : ['hygiene'],
        );
    }

    /**
     * Whether a critiqued score survives the floor. Null (uncritiqued) always keeps.
     */
    public function critiqueSurvives(?int $verifiedScore): bool
    {
        return $verifiedScore === null || $verifiedScore >= $this->minCritiqueScore;
    }

    private static function clampScore(int $score): int
    {
        return max(0, min(10, $score));
    }

    /**
     * @param  list<string>|null  $keys
     * @return list<string>|null
     */
    private static function normalizeKeys(?array $keys): ?array
    {
        if ($keys === null) {
            return null;
        }

        $out = [];
        foreach ($keys as $key) {
            if (is_string($key) && $key !== '') {
                $out[] = $key;
            }
        }

        $out = array_values(array_unique($out));

        return $out === [] ? null : $out;
    }
}
