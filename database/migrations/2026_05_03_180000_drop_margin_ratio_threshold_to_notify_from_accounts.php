<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the unused `accounts.margin_ratio_threshold_to_notify` column.
 *
 * The column was scaffolded with the intent of warning the operator
 * when an account's margin ratio approached liquidation territory,
 * but the bot does not own liquidation handling — that is explicitly
 * out of scope (operators handle liquidations directly via the
 * exchange UI). The column has zero readers in production code, the
 * migration default (1.50) and the AccountFactory default (0.80)
 * disagreed on the scale, and the dashboard stub used the unrelated
 * Binance-UI 0–100% percent. The column carries no consistent
 * meaning to remove a future ambiguity for any downstream feature
 * that would otherwise inherit it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn('margin_ratio_threshold_to_notify');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->decimal('margin_ratio_threshold_to_notify', 5, 2)
                ->default(1.50)
                ->after('total_limit_orders_filled_to_notify')
                ->comment('Minimum margin ratio to start notifying the account admin');
        });
    }
};
