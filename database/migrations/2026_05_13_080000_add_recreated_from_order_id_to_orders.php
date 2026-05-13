<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the `orders.recreated_from_order_id` lineage column.
 *
 * Background: RecreateCancelledOrderJob was not idempotent across retries
 * after a successful `apiPlace()`. A worker death between exchange
 * placement and `doubleCheck()` caused the framework to retry
 * `computeApiable()` against a fresh `$this->newOrder` (null on
 * reconstruction), which wrote a second local Order row and placed a
 * duplicate exchange order — the same failure shape that v1.39.0 closed
 * for `PlaceMarketOrderJob` / `PlaceLimitOrderJob`. Without a lineage
 * column on the cancelled order, no caller could detect the prior
 * placement and short-circuit.
 *
 * The replacement Order row now stamps the cancelled order's id at
 * creation time. `RecreateCancelledOrderJob::startOrFail()` queries
 * for the prior replacement and resumes from it; `computeApiable()`
 * skips `Order::create` + `apiPlace()` whenever the resumed order
 * already carries an `exchange_order_id`.
 *
 * Nullable on purpose: only replacements stamp the link. Original
 * orders (LIMIT entries placed by DispatchLimitOrdersJob, etc.) leave
 * it null. No backfill needed — the lineage is forward-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('recreated_from_order_id')
                ->nullable()
                ->after('id')
                ->comment('Link to the cancelled/expired order this row replaces — set once at Order::create when RecreateCancelledOrderJob writes the replacement, never rewritten. Used by startOrFail to resume a prior placement on retry.');

            $table->index('recreated_from_order_id', 'orders_recreated_from_order_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_recreated_from_order_id_index');
            $table->dropColumn('recreated_from_order_id');
        });
    }
};
