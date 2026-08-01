<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $timestamp = now();
        $canonical = 'market_regime_score_changed';
        $values = [
            'title' => 'BSCS score state changed',
            'description' => 'BSCS left zero, reached 100, or returned to zero.',
            'detailed_description' => 'Sent to the trader app when BSCS moves from 0 to a non-zero score, reaches 100, or returns from a non-zero score to 0. Intermediate non-zero score changes stay silent. Trading-pause and recovery notifications remain separate.',
            'usage_reference' => 'ComputeMarketRegimeJob',
            'default_severity' => 'info',
            'verified' => true,
            'cache_duration' => 0,
            'cache_key' => null,
            'updated_at' => $timestamp,
        ];

        if (! DB::table('notifications')->where('canonical', $canonical)->exists()) {
            $values['created_at'] = $timestamp;
            $values['is_active'] = true;
        }

        DB::table('notifications')->updateOrInsert(
            ['canonical' => $canonical],
            $values,
        );
    }

    public function down(): void
    {
        DB::table('notifications')
            ->where('canonical', 'market_regime_score_changed')
            ->delete();
    }
};
