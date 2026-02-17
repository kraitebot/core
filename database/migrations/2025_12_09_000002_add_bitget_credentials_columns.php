<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add BitGet API credential columns to accounts and martingalian tables.
     * BitGet requires 3 credentials: api_key, api_secret, and passphrase.
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->longText('bitget_api_key')->nullable()->after('kucoin_passphrase');
            $table->longText('bitget_api_secret')->nullable()->after('bitget_api_key');
            $table->longText('bitget_passphrase')->nullable()->after('bitget_api_secret');
        });

        Schema::table('martingalian', function (Blueprint $table) {
            $table->longText('bitget_api_key')->nullable()->after('kucoin_passphrase');
            $table->longText('bitget_api_secret')->nullable()->after('bitget_api_key');
            $table->longText('bitget_passphrase')->nullable()->after('bitget_api_secret');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['bitget_api_key', 'bitget_api_secret', 'bitget_passphrase']);
        });

        Schema::table('martingalian', function (Blueprint $table) {
            $table->dropColumn(['bitget_api_key', 'bitget_api_secret', 'bitget_passphrase']);
        });
    }
};
