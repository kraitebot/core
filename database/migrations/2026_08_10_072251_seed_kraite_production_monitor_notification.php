<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('notifications')->updateOrInsert(
            ['canonical' => 'kraite_production_monitor'],
            [
                'title' => 'Kraite Production Monitor',
                'description' => 'Operator alert emitted by the bounded production monitor.',
                'detailed_description' => 'Non-critical monitor findings deduplicate by stable signal for 24 hours. A distinct signal and a recovered:<signal> event remain independently deliverable. Critical trading-safety findings may explicitly override the throttle.',
                'usage_reference' => 'do kraite-monitor',
                'default_severity' => 'high',
                'verified' => 1,
                'is_active' => true,
                'cache_duration' => 86_400,
                'cache_key' => json_encode(['signal'], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('notifications')
            ->where('canonical', 'kraite_production_monitor')
            ->delete();
    }
};
