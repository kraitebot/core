<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * This migration simplifies the exchange_symbols architecture by:
     * 1. Adding token and quote columns directly to exchange_symbols
     * 2. Converting account quote references from FK to string columns
     * 3. Dropping the quotes table
     * 4. Dropping the base_asset_mappers table
     */
    public function up(): void
    {
        // Step 1: Add token, quote, and asset columns to exchange_symbols
        Schema::table('exchange_symbols', function (Blueprint $table) {
            $table->string('token', 50)->after('id')->nullable();
            $table->string('quote', 20)->after('token')->nullable();
            $table->string('asset', 50)->after('quote')->nullable(); // Raw exchange pair (e.g., PF_XBTUSD, BTCUSDT)
        });

        // Step 2: Migrate data - populate token and quote from relationships
        // Note: This assumes the old symbol_id and quote_id relationships still exist
        DB::statement('
            UPDATE exchange_symbols es
            INNER JOIN symbols s ON es.symbol_id = s.id
            INNER JOIN quotes q ON es.quote_id = q.id
            SET es.token = s.token, es.quote = q.canonical
        ');

        // Step 3: Make token and quote NOT NULL after data migration
        Schema::table('exchange_symbols', function (Blueprint $table) {
            $table->string('token', 50)->nullable(false)->change();
            $table->string('quote', 20)->nullable(false)->change();
        });

        // Step 4: Make symbol_id nullable (keep for optional CMC metadata link)
        Schema::table('exchange_symbols', function (Blueprint $table) {
            $table->unsignedBigInteger('symbol_id')->nullable()->change();
        });

        // Step 5: Drop quote_id from exchange_symbols
        Schema::table('exchange_symbols', function (Blueprint $table) {
            // Drop the unique constraint that includes quote_id
            $table->dropUnique('exchange_symbols_symbol_id_api_system_id_quote_id_unique');
        });

        Schema::table('exchange_symbols', function (Blueprint $table) {
            $table->dropColumn('quote_id');
        });

        // Step 6: Add new unique constraint with token instead of quote_id
        Schema::table('exchange_symbols', function (Blueprint $table) {
            $table->unique(['token', 'api_system_id', 'quote'], 'exchange_symbols_token_api_system_id_quote_unique');
        });

        // Step 7: Add portfolio_quote and trading_quote columns to accounts
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('portfolio_quote', 20)->nullable()->after('trade_configuration_id');
            $table->string('trading_quote', 20)->nullable()->after('portfolio_quote');
        });

        // Step 8: Migrate account quote data
        DB::statement('
            UPDATE accounts a
            LEFT JOIN quotes pq ON a.portfolio_quote_id = pq.id
            LEFT JOIN quotes tq ON a.trading_quote_id = tq.id
            SET a.portfolio_quote = pq.canonical, a.trading_quote = tq.canonical
        ');

        // Step 9: Drop old quote FK columns from accounts
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['portfolio_quote_id', 'trading_quote_id']);
        });

        // Step 10: Drop quotes table
        Schema::dropIfExists('quotes');

        // Step 11: Drop base_asset_mappers table
        Schema::dropIfExists('base_asset_mappers');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Recreate base_asset_mappers table
        Schema::create('base_asset_mappers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('api_system_id');
            $table->string('symbol_token');
            $table->string('exchange_token');
            $table->timestamps();

            $table->index(['api_system_id', 'symbol_token'], 'idx_api_symbol_token');
        });

        // Recreate quotes table
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->string('canonical')->unique();
            $table->string('name');
            $table->timestamps();
        });

        // Restore accounts table
        Schema::table('accounts', function (Blueprint $table) {
            $table->unsignedBigInteger('portfolio_quote_id')->nullable()->after('trade_configuration_id');
            $table->unsignedBigInteger('trading_quote_id')->nullable()->after('portfolio_quote_id');
            $table->dropColumn(['portfolio_quote', 'trading_quote']);
        });

        // Restore exchange_symbols table
        Schema::table('exchange_symbols', function (Blueprint $table) {
            $table->dropUnique('exchange_symbols_token_api_system_id_quote_unique');
        });

        Schema::table('exchange_symbols', function (Blueprint $table) {
            $table->unsignedBigInteger('quote_id')->after('symbol_id');
            $table->dropColumn(['token', 'quote', 'asset']);
            $table->unsignedBigInteger('symbol_id')->nullable(false)->change();
        });

        Schema::table('exchange_symbols', function (Blueprint $table) {
            $table->unique(['symbol_id', 'api_system_id', 'quote_id'], 'exchange_symbols_symbol_id_api_system_id_quote_id_unique');
        });
    }
};
