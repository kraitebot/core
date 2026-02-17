<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add KuCoin API credential columns to accounts and martingalian tables.
     * KuCoin requires 3 credentials: api_key, api_secret, and passphrase.
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->longText('kucoin_api_key')->nullable()->after('kraken_private_key');
            $table->longText('kucoin_api_secret')->nullable()->after('kucoin_api_key');
            $table->longText('kucoin_passphrase')->nullable()->after('kucoin_api_secret');
        });

        Schema::table('martingalian', function (Blueprint $table) {
            $table->longText('kucoin_api_key')->nullable()->after('kraken_private_key');
            $table->longText('kucoin_api_secret')->nullable()->after('kucoin_api_key');
            $table->longText('kucoin_passphrase')->nullable()->after('kucoin_api_secret');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['kucoin_api_key', 'kucoin_api_secret', 'kucoin_passphrase']);
        });

        Schema::table('martingalian', function (Blueprint $table) {
            $table->dropColumn(['kucoin_api_key', 'kucoin_api_secret', 'kucoin_passphrase']);
        });
    }
};
