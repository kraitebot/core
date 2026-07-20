<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $conflictingSymbol = DB::table('positions')
            ->select(['account_id', 'exchange_symbol_id'])
            ->whereNotNull('exchange_symbol_id')
            ->whereNotNull('is_open')
            ->groupBy('account_id', 'exchange_symbol_id')
            ->havingRaw('COUNT(*) > 1')
            ->limit(1)
            ->first();

        if ($conflictingSymbol !== null) {
            throw new RuntimeException(sprintf(
                'Cannot enforce one open position per symbol: account %d has multiple open positions for exchange symbol %d.',
                $conflictingSymbol->account_id,
                $conflictingSymbol->exchange_symbol_id,
            ));
        }

        DB::statement(<<<'SQL'
            ALTER TABLE positions
              DROP INDEX ux_positions_open_slot,
              ADD UNIQUE INDEX ux_positions_open_slot (account_id, exchange_symbol_id, is_open)
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE positions
              DROP INDEX ux_positions_open_slot,
              ADD UNIQUE INDEX ux_positions_open_slot (account_id, exchange_symbol_id, direction, is_open)
        SQL);
    }
};
