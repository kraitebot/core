<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE positions
              MODIFY COLUMN is_open TINYINT GENERATED ALWAYS AS (
                CASE
                  WHEN status IN ('new','opening','active','syncing','waping','closing','cancelling') THEN 1
                  ELSE NULL
                END
              ) VIRTUAL
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE positions
              MODIFY COLUMN is_open TINYINT GENERATED ALWAYS AS (
                CASE
                  WHEN status IN ('new','opening','active','syncing','closing','cancelling') THEN 1
                  ELSE NULL
                END
              ) VIRTUAL
        SQL);
    }
};
