<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Partial unique constraint on (account_id, exchange_symbol_id, direction)
 * for positions in any non-terminal status.
 *
 * MySQL has no native partial indexes, so this uses a VIRTUAL generated
 * column `is_open` that is 1 when the position is active-ish and NULL
 * when it reaches a terminal state. Unique indexes ignore NULLs, so
 * closed/cancelled/failed rows never collide, but two concurrent
 * "open" rows with the same tuple will be rejected by the DB.
 *
 * Active statuses:  new, opening, active, syncing, closing, cancelling
 * Terminal:         closed, cancelled, failed
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE positions
              ADD COLUMN is_open TINYINT GENERATED ALWAYS AS (
                CASE
                  WHEN status IN ('new','opening','active','syncing','closing','cancelling') THEN 1
                  ELSE NULL
                END
              ) VIRTUAL
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE positions
              ADD UNIQUE INDEX ux_positions_open_slot (account_id, exchange_symbol_id, direction, is_open)
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE positions DROP INDEX ux_positions_open_slot');
        DB::statement('ALTER TABLE positions DROP COLUMN is_open');
    }
};
