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
            $table->string('balance_for_trading_basis', 20)
                ->default('total')
                ->after('margin')
                ->comment('Balance snapshot key family used for margin sizing: total or available.');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn('balance_for_trading_basis');
        });
    }
};
