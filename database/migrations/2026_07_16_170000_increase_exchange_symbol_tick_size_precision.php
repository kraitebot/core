<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exchange_symbols', function (Blueprint $table): void {
            $table->decimal('tick_size', 30, 18)->change();
        });

        DB::table('exchange_symbols')
            ->select(['id', 'symbol_information'])
            ->where('tick_size', '<=', 0)
            ->orderBy('id')
            ->chunkById(500, function (Collection $exchangeSymbols): void {
                foreach ($exchangeSymbols as $exchangeSymbol) {
                    $symbolInformation = json_decode((string) $exchangeSymbol->symbol_information, true);
                    if (is_string($symbolInformation)) {
                        $symbolInformation = json_decode($symbolInformation, true);
                    }

                    $tickSize = is_array($symbolInformation)
                        ? $symbolInformation['tickSize'] ?? null
                        : null;

                    if (! is_numeric($tickSize) || (float) $tickSize <= 0) {
                        continue;
                    }

                    DB::table('exchange_symbols')
                        ->where('id', $exchangeSymbol->id)
                        ->update(['tick_size' => (string) $tickSize]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('exchange_symbols', function (Blueprint $table): void {
            $table->decimal('tick_size', 20, 8)->change();
        });
    }
};
