<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('notifications')
            ->where('canonical', 'market_regime_score_changed')
            ->update([
                'description' => 'Legacy BSCS score transition definition retained inactive; only pause and recovery events notify traders.',
                'is_active' => false,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('notifications')
            ->where('canonical', 'market_regime_score_changed')
            ->update([
                'description' => 'BSCS left zero, reached 100, or returned to zero.',
                'is_active' => true,
                'updated_at' => now(),
            ]);
    }
};
