<?php

declare(strict_types=1);

namespace Kraite\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Kraite\Core\Models\Notification;

/**
 * Seeds the three Black Swan Composite Score (BSCS) notification
 * canonicals.
 *
 * **Phase 1 leaves them with `is_active = false`** — the message-builder
 * arms exist (see `NotificationMessageBuilder`) so the dispatcher can
 * preflight without hitting the fail-loud `default`, but no live
 * Pushover/email goes out until Phase 2 activates them.
 *
 * Spec: `~/docs/kraite/black-swan-logic.md` "Notifications".
 */
final class MarketRegimeNotificationsSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            [
                'canonical' => 'market_regime_critical',
                'title' => 'BSCS Critical — opens paused',
                'description' => 'Black Swan Composite Score crossed into Critical band (≥ 80). Phase 2 pauses new opens; Phase 1 telemetry only.',
                'default_severity' => 'high',
                'cache_duration' => 3600,
                'cache_key' => json_encode(['canonical' => 'market_regime_critical']),
                'is_active' => false,
                'verified' => false,
            ],
            [
                'canonical' => 'market_regime_recovered',
                'title' => 'BSCS Recovered',
                'description' => 'Black Swan Composite Score has fallen out of Critical. Phase 2 resumes opens; Phase 1 telemetry only.',
                'default_severity' => 'info',
                'cache_duration' => 3600,
                'cache_key' => json_encode(['canonical' => 'market_regime_recovered']),
                'is_active' => false,
                'verified' => false,
            ],
            [
                'canonical' => 'market_regime_compute_stale',
                'title' => 'BSCS Compute Stale',
                'description' => 'Hourly BSCS recompute job has not run for ≥ 24h. Phase 2 gate fail-opens on stale signal; investigate Horizon.',
                'default_severity' => 'high',
                'cache_duration' => 3600,
                'cache_key' => json_encode(['canonical' => 'market_regime_compute_stale']),
                'is_active' => false,
                'verified' => false,
            ],
        ];

        foreach ($rows as $row) {
            Notification::updateOrCreate(
                ['canonical' => $row['canonical']],
                $row,
            );
        }
    }
}
