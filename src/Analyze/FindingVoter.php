<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Analyze;

/**
 * R1 multi-sample voting. Aggregates the validated findings of k independent
 * review samples (each sample = one fan-out of the corpus over the same files)
 * and keeps only the findings that ≥ `minVotes` samples agree on.
 *
 * The surviving finding's confidence is overwritten with its **vote-share**
 * (votes / k) — a calibrated agreement signal — NOT the model's self-reported
 * confidence, which is miscalibrated and easy to inflate.
 *
 * Identity: two findings are the same when they share pattern key, file, and
 * line. A finding reported more than once within a single sample still counts
 * as one vote for that sample (you cannot out-vote yourself by repeating).
 */
final class FindingVoter
{
    /**
     * @param  list<list<PatternMatch>>  $samples  validated matches, one inner list per sample
     * @return list<PatternMatch> survivors in first-seen order, confidence = vote-share
     */
    public function tally(array $samples, int $minVotes): array
    {
        $sampleCount = count($samples);
        if ($sampleCount === 0) {
            return [];
        }

        /** @var array<string, PatternMatch> $representative */
        $representative = [];
        /** @var array<string, int> $votes */
        $votes = [];

        foreach ($samples as $sample) {
            $counted = [];
            foreach ($sample as $match) {
                $key = $this->voteKey($match);

                if (! array_key_exists($key, $representative)) {
                    $representative[$key] = $match;
                }

                if (! isset($counted[$key])) {
                    $counted[$key] = true;
                    $votes[$key] = ($votes[$key] ?? 0) + 1;
                }
            }
        }

        $survivors = [];
        foreach ($representative as $key => $match) {
            $count = $votes[$key] ?? 0;
            if ($count >= $minVotes) {
                $survivors[] = $match->withConfidence($count / $sampleCount);
            }
        }

        return $survivors;
    }

    private function voteKey(PatternMatch $match): string
    {
        return $match->patternKey.'|'.$match->file.'|'.$match->line;
    }
}
