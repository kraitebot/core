<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Exchange-symbol rename lifecycle (TON → GRAM, 2026-07).
 *
 * When an exchange renames a contract, the old ticker vanishes from the
 * exchange feed and the new one arrives as a brand-new exchange_symbols
 * row. The old row is retired (applySystemBlock 'renamed') and the new
 * row records its ancestry through `renamed_from_exchange_symbol_id`.
 *
 * restrictOnDelete is deliberate: the retired row carries the only copy
 * of the coin's pre-rename candle history (providers restart the series
 * under the new name), so it must never be deletable while a successor
 * references it.
 *
 * Also seeds the two notification canonicals fired by rename detection:
 *  - exchange_symbol_renamed: a rename was detected and auto-processed.
 *  - exchange_symbol_rename_suspected: old/new listing resolve to the
 *    same coin but the old listing vanished outside the recency window —
 *    reported for a human decision, nothing auto-processed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exchange_symbols', function (Blueprint $table): void {
            $table->foreignId('renamed_from_exchange_symbol_id')
                ->nullable()
                ->after('symbol_id')
                ->constrained('exchange_symbols')
                ->restrictOnDelete();
        });

        DB::table('notifications')->updateOrInsert(
            ['canonical' => 'exchange_symbol_renamed'],
            [
                'title' => 'Exchange Symbol Renamed',
                'description' => 'An exchange renamed a listed contract — the old entry was retired and the new entry entered the standard evaluation funnel',
                'detailed_description' => 'Sent by DiscoverCMCTokenForExchangeSymbolJob when a freshly linked exchange symbol resolves to the same CoinMarketCap identity as a recently delisting-flagged sibling on the same exchange and quote. The old row is system-blocked with reason `renamed` (one-way switch), the new row records `renamed_from_exchange_symbol_id`, and the new listing re-earns tradability through the normal backtesting funnel. Open positions on the retired row are reported for a human decision — never auto-migrated.',
                'usage_reference' => 'kraite:cron-refresh-exchange-symbols',
                'default_severity' => 'high',
                'verified' => 1,
                'is_active' => true,
                'cache_duration' => 3600,
                'cache_key' => json_encode(['exchange_symbol']),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('notifications')->updateOrInsert(
            ['canonical' => 'exchange_symbol_rename_suspected'],
            [
                'title' => 'Possible Exchange Symbol Rename',
                'description' => 'A new listing resolves to the same coin as an old delisted entry, but the delisting is too old to auto-link — human review needed',
                'detailed_description' => 'Sent by DiscoverCMCTokenForExchangeSymbolJob when the rename shape matches (same CoinMarketCap identity, same exchange and quote, old row delisting-flagged) but the old listing vanished outside the recency window. Months-apart disappear/appear pairs can be a genuine delist plus an unrelated relisting (or a 1000x contract migration), so nothing is blocked or linked automatically.',
                'usage_reference' => 'kraite:cron-refresh-exchange-symbols',
                'default_severity' => 'medium',
                'verified' => 1,
                'is_active' => true,
                'cache_duration' => 3600,
                'cache_key' => json_encode(['exchange_symbol']),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        Schema::table('exchange_symbols', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('renamed_from_exchange_symbol_id');
        });

        DB::table('notifications')
            ->whereIn('canonical', ['exchange_symbol_renamed', 'exchange_symbol_rename_suspected'])
            ->delete();
    }
};
