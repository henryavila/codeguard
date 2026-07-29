<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Analyze;

/**
 * Data-driven PR action policy (v1). Pure function of pattern key + severity + critique score.
 *
 * Security-critical patterns block; G3 data/architecture smells request change when
 * the critique floor is met (or the finding was not critiqued); everything else is info.
 */
final class FindingActionClassifier
{
    /** @var list<string> */
    private const BLOCK_CRITICAL = [
        'raw-sql-injection',
        'missing-authorization',
        'mass-assignment',
    ];

    /** @var list<string> */
    private const REQUEST_CHANGE_TX = [
        'missing-database-transaction',
    ];

    /** @var list<string> */
    private const REQUEST_CHANGE_QUERY = [
        'eloquent-n-plus-one',
        'unbounded-query',
    ];

    /** @var list<string> */
    private const REQUEST_CHANGE_LAYER = [
        'service-layer',
        'layer-dependency-direction',
        'bounded-contexts',
        'no-circular-dependencies',
    ];

    public function classify(PatternMatch $match): FindingAction
    {
        $key = $match->patternKey;

        if (in_array($key, self::BLOCK_CRITICAL, true) && $match->severity === Severity::Critical) {
            return FindingAction::Block;
        }

        if (in_array($key, self::REQUEST_CHANGE_TX, true)
            && ($match->verifiedScore === null || $match->verifiedScore >= 4)) {
            return FindingAction::RequestChange;
        }

        if (in_array($key, self::REQUEST_CHANGE_QUERY, true)
            && $match->verifiedScore !== null
            && $match->verifiedScore >= 4) {
            return FindingAction::RequestChange;
        }

        if (in_array($key, self::REQUEST_CHANGE_LAYER, true)) {
            return FindingAction::RequestChange;
        }

        return FindingAction::Info;
    }
}
