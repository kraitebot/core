<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the notification canonical consumed by the drift spotter's
 * Scope 2b WAP under-application self-heal (kraite:cron-check-drifts):
 *
 *  - position_wap_self_healed: fires once per pass for every `active`
 *    position whose resting take-profit quantity under-covers the FILLED
 *    entry ladder (MARKET + DCA LIMITs) — the signature of a WAP resize
 *    lost mid-flight (2026-07-13, position #394 FILUSDT). The spotter
 *    dispatches ApplyWapJob; this notification is the audit trail.
 *
 * Idempotent via updateOrInsert on canonical.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('notifications')->updateOrInsert(
            ['canonical' => 'position_wap_self_healed'],
            [
                'title' => 'WAP Self-Heal Dispatched',
                'description' => 'An active position\'s take-profit under-covered its filled DCA ladder — the drift spotter re-dispatched the WAP workflow',
                'detailed_description' => 'Sent by kraite:cron-check-drifts (Scope 2b) when an `active` position has at least one FILLED DCA LIMIT order and the summed FILLED entry quantity (MARKET + LIMITs, formatted at the symbol\'s quantity precision) exceeds the resting NEW take-profit\'s quantity. That is the signature of a WAP resize lost mid-flight: the exchange absorbed the DCA fill but the take-profit never grew to cover it. The spotter dispatches ApplyWapJob (idempotent, re-verifies against a fresh exchange snapshot before modifying the TP); this notification is the audit trail. Re-fires every 5 minutes while the under-coverage persists — persistence past a couple of passes means the heal chain itself is failing and needs operator eyes.',
                'usage_reference' => 'kraite:cron-check-drifts',
                'default_severity' => 'high',
                'verified' => 1,
                'is_active' => true,
                'cache_duration' => 60,
                'cache_key' => json_encode(['position']),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('notifications')
            ->where('canonical', 'position_wap_self_healed')
            ->delete();
    }
};
