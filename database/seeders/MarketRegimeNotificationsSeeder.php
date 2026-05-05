<?php

declare(strict_types=1);

namespace Kraite\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Kraite\Core\Models\Notification;

/**
 * Seeds the three Black Swan Composite Score (BSCS) notification
 * canonicals — fully active. The message-builder arms in
 * `NotificationMessageBuilder` already format these payloads, so
 * the canonicals dispatch live Pushover / mail / telegram alerts
 * the moment `AnalyseBscsJob` arms / re-arms / releases the
 * cooldown column.
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
                'description' => 'Black Swan Composite Score crossed into Critical band (≥ 80). New opens paused for the configured cooldown window; existing positions continue to be managed.',
                'default_severity' => 'high',
                'cache_duration' => 3600,
                'cache_key' => json_encode(['canonical' => 'market_regime_critical']),
                'is_active' => true,
                'verified' => true,
            ],
            [
                'canonical' => 'market_regime_recovered',
                'title' => 'BSCS Recovered',
                'description' => 'Black Swan Composite Score has fallen out of Critical and the cooldown window has expired. New opens resume.',
                'default_severity' => 'info',
                'cache_duration' => 3600,
                'cache_key' => json_encode(['canonical' => 'market_regime_recovered']),
                'is_active' => true,
                'verified' => true,
            ],
            [
                'canonical' => 'market_regime_compute_stale',
                'title' => 'BSCS Compute Stale',
                'description' => 'Hourly BSCS recompute job has not run for ≥ 6h. The opens-gate fail-opens on a stale signal; investigate Horizon / scheduler / DataPipeline.',
                'default_severity' => 'high',
                'cache_duration' => 3600,
                'cache_key' => json_encode(['canonical' => 'market_regime_compute_stale']),
                'is_active' => true,
                'verified' => true,
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
