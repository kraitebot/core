<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-replacement-per-cancelled-order is an invariant, not a convention:
 * lineage chains forward (a cancelled replacement is re-replaced from its
 * OWN id, never the original's), so each order is replaced at most once.
 * RecreateCancelledOrderJob now serializes concurrent recreations with a
 * row lock; this unique index is the schema backstop for any writer that
 * bypasses that path — a second insert for the same parent fails loudly
 * instead of producing a duplicate live exchange order.
 *
 * Verified before shipping: production carries zero duplicate parents
 * (2026-07-11), so the swap is safe on live data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_recreated_from_order_id_index');
            $table->unique('recreated_from_order_id', 'orders_recreated_from_order_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique('orders_recreated_from_order_id_unique');
            $table->index('recreated_from_order_id', 'orders_recreated_from_order_id_index');
        });
    }
};
