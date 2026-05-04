<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Splits the hot mark_price + mark_price_synced_at pair out of
 * `exchange_symbols` into a dedicated narrow table so the
 * 1-second-cadence price daemon's bulk UPDATE no longer contends
 * with every other writer of `exchange_symbols` (indicator
 * pipeline, ConcludeSymbolsDirectionCommand burst at :30, api
 * request logging that touches the relation, etc.).
 *
 * Memory ref: db_lock_contention_mark_price_daemon.md
 *
 * Migration shape — Phase A of the cutover:
 *   1. Create the new table (this migration).
 *   2. Backfill from the existing columns (this migration's seed
 *      block) so post-deploy the new table is immediately
 *      authoritative.
 *   3. Daemon write paths cut over to the new table in the same
 *      release.
 *   4. The old columns on exchange_symbols stay populated as a
 *      historical snapshot but become read-side fallback — the
 *      accessor on the model reads from the new table first.
 *   5. A LATER migration (after parity soak) drops the old
 *      columns from exchange_symbols.
 *
 * Why a 1:1 table rather than a JSON column on exchange_symbols:
 *   1:1 allows the daemon to UPDATE without holding a lock on the
 *   parent row, which is exactly the contention vector we're
 *   killing. A JSON column on the same row would not solve the
 *   lock-contention problem — it would simply move the column
 *   without changing the row that gets locked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_symbol_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('exchange_symbol_id')
                ->unique()
                ->constrained('exchange_symbols')
                ->restrictOnDelete();
            $table->decimal('mark_price', 20, 8)->nullable();
            $table->timestamp('mark_price_synced_at')->nullable();
            $table->timestamps();

            // Hot read path: the freshness watchdog filters on
            // mark_price_synced_at < threshold across every
            // tradeable symbol. Index for that range scan.
            $table->index('mark_price_synced_at');
        });

        // Backfill from the legacy columns. Single-pass INSERT...SELECT
        // so even on a 2K-symbol table this completes in milliseconds.
        // The backfill is non-destructive — the source columns stay
        // in place until the cutover-soak migration drops them later.
        DB::statement(<<<'SQL'
            INSERT INTO exchange_symbol_prices
                (exchange_symbol_id, mark_price, mark_price_synced_at, created_at, updated_at)
            SELECT
                id,
                mark_price,
                mark_price_synced_at,
                COALESCE(created_at, NOW()),
                COALESCE(updated_at, NOW())
            FROM exchange_symbols
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_symbol_prices');
    }
};
