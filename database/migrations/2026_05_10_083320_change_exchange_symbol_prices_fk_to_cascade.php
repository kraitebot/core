<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exchange_symbol_prices', function (Blueprint $table): void {
            $table->dropForeign(['exchange_symbol_id']);
            $table->foreignId('exchange_symbol_id')
                ->change()
                ->constrained('exchange_symbols')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('exchange_symbol_prices', function (Blueprint $table): void {
            $table->dropForeign(['exchange_symbol_id']);
            $table->foreignId('exchange_symbol_id')
                ->change()
                ->constrained('exchange_symbols')
                ->restrictOnDelete();
        });
    }
};
