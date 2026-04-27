<?php

declare(strict_types=1);

namespace Kraite\Core\Enums;

/**
 * Three-tier freshness model for the BSCS pipeline. Replaces the
 * earlier 2-tier (fresh / fail-open) which was too permissive during
 * outages-correlated-with-volatility.
 *
 *   - Fresh       — synced_at within `freshness_max_seconds` (default
 *                   6900s = 115 min). Trust the score, run gate
 *                   normally.
 *
 *   - StaleSoft   — synced_at older than fresh but within the soft
 *                   cutoff (default 6h). Preserve last cooldown/scaler
 *                   state so we don't whipsaw on a single missed cron.
 *                   Warn once via appLog. Gate still respects an
 *                   active cooldown.
 *
 *   - StaleHard   — synced_at older than 6h, OR null (never computed).
 *                   Compute pipeline is genuinely broken; cooldown
 *                   could be arming on hours-old data. Fail OPEN —
 *                   gate allows new opens — and fire the
 *                   `market_regime_compute_stale` notification so the
 *                   operator investigates Horizon. "Missed pause" is
 *                   preferable to "stuck halt" on the autonomous bot.
 *
 * @see ~/docs/kraite/black-swan-logic.md (Phase 2.1B)
 */
enum BscsStaleness: string
{
    case Fresh = 'fresh';
    case StaleSoft = 'stale_soft';
    case StaleHard = 'stale_hard';

    public function isFresh(): bool
    {
        return $this === self::Fresh;
    }

    public function isStale(): bool
    {
        return $this !== self::Fresh;
    }

    public function failsOpen(): bool
    {
        return $this === self::StaleHard;
    }
}
