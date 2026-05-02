<?php

declare(strict_types=1);

namespace Kraite\Core\Support\TokenScoring;

/**
 * Multiplier in [0, 1] that penalises a candidate whose 1-day BTC
 * correlation is too close (within `threshold`) to that of any
 * symbol already picked in the same selection batch. Forces
 * structural diversity within a single position-creation cycle so
 * a 6-LONG book is not 6 essentially-identical bets on BTC.
 *
 * Comparison is restricted to symbols with the SAME correlation
 * sign as the candidate. A LONG ladder of LONG slots typically
 * picks all positively-BTC-correlated tokens; a negatively-correlated
 * candidate (one that hedges BTC) is structurally distinct and
 * never penalised against same-side picks.
 *
 * The penalty ramps linearly from `minPenalty` (at zero distance,
 * i.e. an exact correlation match against a picked symbol) up to
 * `1.0` (at distance == threshold). Beyond the threshold the
 * candidate is considered diverse enough and the multiplier is `1.0`.
 *
 * Returns `1.0` when the batch is empty or contains no same-sign
 * symbols — diversity has nothing to enforce.
 */
final class BatchDiversificationPenalty
{
    /**
     * @param  array<int, float>  $alreadyPickedCorrelations
     */
    public static function for(
        float $candidateCorrelation,
        array $alreadyPickedCorrelations,
        float $threshold = 0.10,
        float $minPenalty = 0.5,
    ): float {
        if ($alreadyPickedCorrelations === []) {
            return 1.0;
        }

        $candidateSign = $candidateCorrelation >= 0.0;

        $sameSign = array_filter(
            $alreadyPickedCorrelations,
            static fn (float $picked): bool => ($picked >= 0.0) === $candidateSign,
        );

        if ($sameSign === []) {
            return 1.0;
        }

        $minDistance = INF;

        foreach ($sameSign as $picked) {
            $distance = abs($candidateCorrelation - $picked);

            if ($distance < $minDistance) {
                $minDistance = $distance;
            }
        }

        if ($minDistance >= $threshold) {
            return 1.0;
        }

        return $minPenalty + (1.0 - $minPenalty) * ($minDistance / $threshold);
    }
}
