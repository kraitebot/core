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
            $table->boolean('allow_other_positions')
                ->default(false)
                ->after('on_hedge_mode')
                ->comment('When true, the operator/user can place positions on this exchange account outside Kraite. The orphan-cleanup watchdog ignores any exchange position with no matching local opened() Position row. When false (Kraite-exclusive), every such orphan position is closed automatically on the next health-check tick.');

            $table->boolean('allow_other_orders')
                ->default(false)
                ->after('allow_other_positions')
                ->comment('When true, the operator/user can place orders directly on this exchange account. The orphan-cleanup watchdog cancels only Kraite-leftover orphans (matched by client_order_id against positions Kraite closed within the configurable match window). When false (Kraite-exclusive), every orphan order is cancelled regardless of origin.');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn(['allow_other_positions', 'allow_other_orders']);
        });
    }
};
