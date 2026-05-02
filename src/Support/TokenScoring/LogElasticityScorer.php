<?php

declare(strict_types=1);

namespace Kraite\Core\Support\TokenScoring;

/**
 * Elasticity-vs-correlation score with logarithmic compression on
 * the elasticity term so a freak outlier (e.g. 100× elasticity) does
 * not dwarf reasonable candidates with strong correlation.
 *
 * Without the log, score is `elasticity × |correlation|` and a token
 * with a 100× / 0.4 profile (score 40) buries a 5× / 0.9 token
 * (score 4.5). The log compression collapses that gap to ~1.5×.
 *
 * Sign of either input is irrelevant — the absolute value is what
 * the scorer reasons about. Direction-specific filtering happens
 * upstream.
 */
final class LogElasticityScorer
{
    public static function score(float $elasticity, float $correlation): float
    {
        return log(1.0 + abs($elasticity)) * abs($correlation);
    }
}
