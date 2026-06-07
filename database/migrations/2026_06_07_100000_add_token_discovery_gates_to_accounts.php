<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->boolean('use_correlation_sign_filter')
                ->nullable()
                ->after('allow_other_orders')
                ->comment('Per-account override for the BTC-bias correlation-sign gate during token discovery. NULL = inherit the global config default (kraite.token_discovery.require_matching_correlation_sign). true = enforce the gate (only tokens whose BTC-correlation sign matches the desired side qualify). false = skip the gate for this account. Replaces the env-only TOKEN_DISCOVERY_REQUIRE_MATCHING_CORRELATION_SIGN for per-account control.');

            $table->boolean('use_btc_bias_restriction')
                ->nullable()
                ->after('use_correlation_sign_filter')
                ->comment('Per-account override for the BTC-direction restriction during token discovery. NULL = inherit the global config default (kraite.token_discovery.btc_biased_restriction). true = STRICT (when BTC has no concluded direction, this account opens no positions). false = relaxed (assignment proceeds via the fallback selector even without a BTC direction). Replaces the env-only TOKEN_DISCOVERY_BTC_BIASED_RESTRICTION for per-account control.');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn(['use_correlation_sign_filter', 'use_btc_bias_restriction']);
        });
    }
};
