<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('exchange_symbols', function (Blueprint $table): void {
            $table->timestamp('backtesting_reviewed_at')
                ->nullable()
                ->after('backtesting_review_status')
                ->comment('When the approve/reject decision on this token was taken.');
        });

        // Backfill from the audit trail so decisions taken before this column
        // existed still carry their date. Anything older than the audit
        // retention window stays null rather than inventing a timestamp.
        if (! Schema::hasTable('model_logs')) {
            return;
        }

        DB::table('exchange_symbols')
            ->whereNotNull('backtesting_review_status')
            ->orderBy('id')
            ->chunkById(200, function ($symbols): void {
                foreach ($symbols as $symbol) {
                    $decidedAt = DB::table('model_logs')
                        ->where('loggable_id', $symbol->id)
                        ->where('loggable_type', 'like', '%ExchangeSymbol')
                        ->where('attribute_name', 'backtesting_review_status')
                        ->orderByDesc('id')
                        ->value('created_at');

                    if ($decidedAt === null) {
                        continue;
                    }

                    DB::table('exchange_symbols')
                        ->where('id', $symbol->id)
                        ->update(['backtesting_reviewed_at' => $decidedAt]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('exchange_symbols', function (Blueprint $table): void {
            $table->dropColumn('backtesting_reviewed_at');
        });
    }
};
