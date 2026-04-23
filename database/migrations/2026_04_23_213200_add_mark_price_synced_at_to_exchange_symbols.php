<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exchange_symbols', function (Blueprint $table) {
            // Stamped by StreamBinancePricesCommand on every WebSocket
            // tick. Selection-time code can use this to treat symbols with
            // stale mark_price as not-currently-tradeable even when all
            // other gates pass. Nullable for rows that haven't been
            // touched by the stream yet.
            $table->timestamp('mark_price_synced_at')
                ->nullable()
                ->after('mark_price');
        });
    }

    public function down(): void
    {
        Schema::table('exchange_symbols', function (Blueprint $table) {
            $table->dropColumn('mark_price_synced_at');
        });
    }
};
