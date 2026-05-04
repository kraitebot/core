<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the `mark_price_synced_at` secondary index on
 * `exchange_symbol_prices`.
 *
 * The mark-price daemon emits a bulk UPDATE every ~1s touching
 * `mark_price` and `mark_price_synced_at` for ~500 rows. With the
 * index in place every tick rewrites ~500 secondary-index entries —
 * a hot write amplification path that turns the bulk UPDATE into a
 * 100-second blocker (observed 2026-05-04: id 336 in slow_queries,
 * 100097 ms).
 *
 * The only consumer of the index was the freshness watchdog that
 * filters on `mark_price_synced_at < threshold`. The table holds
 * ~2.3K rows in ~0.16 MB of data; a full scan is essentially free
 * and beats per-tick index maintenance by a wide margin.
 *
 * If the watchdog ever scales past the point where a full scan is
 * cheap, the right answer is materialising a denormalised view or
 * caching the freshness signal — not paying the per-tick index cost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exchange_symbol_prices', function (Blueprint $table): void {
            $table->dropIndex('exchange_symbol_prices_mark_price_synced_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('exchange_symbol_prices', function (Blueprint $table): void {
            $table->index('mark_price_synced_at');
        });
    }
};
