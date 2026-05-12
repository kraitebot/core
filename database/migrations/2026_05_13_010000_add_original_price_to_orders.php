<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the immutable forensic anchors `original_price` and
 * `original_quantity` to the orders table.
 *
 * Background — 2026-05-12 incident: a failed correction on Karine's
 * XRPUSDT order #25 silently rewrote `reference_price` to the user's
 * modified value via `CorrectModifiedOrderJob::complete()`. The next
 * user-side modification then drifted against a corrupted anchor, and
 * the bot would have "restored" the order to the wrong price. The
 * single-column model (reference_price) conflated two distinct
 * concepts: the bot's first-ever placement intent and its current
 * intent post-WAP/recreate. This migration introduces a separate,
 * write-once anchor that survives every correction code path,
 * giving us a forensic ground truth and a suspicion oracle.
 *
 * Both columns are nullable on purpose: legacy rows inserted before
 * this migration ran will be backfilled by the
 * `kraite:backfill-original-prices` artisan command from
 * `api_data_stream` NEW events. The OrderObserver auto-stamps
 * originals from price/quantity on every new row going forward.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('original_price', 20, 8)
                ->nullable()
                ->after('reference_price')
                ->comment('Immutable placement price — forensic anchor; set once at Order::create, never rewritten');

            $table->decimal('original_quantity', 20, 8)
                ->nullable()
                ->after('reference_quantity')
                ->comment('Immutable placement quantity — forensic anchor; set once at Order::create, never rewritten');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['original_price', 'original_quantity']);
        });
    }
};
