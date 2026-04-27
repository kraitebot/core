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
            $table->boolean('override_tp')
                ->default(false)
                ->after('stop_market_initial_percentage')
                ->comment('When true, account.profit_percentage always wins over any symbol-level TP. When false, symbol-level TP is used (with NULL fallback to account).');

            $table->boolean('override_sl')
                ->default(false)
                ->after('override_tp')
                ->comment('When true, account.stop_market_initial_percentage always wins over any symbol-level SL. When false, symbol-level SL is used (with NULL fallback to account).');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn(['override_tp', 'override_sl']);
        });
    }
};
