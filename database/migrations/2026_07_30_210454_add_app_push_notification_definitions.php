<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $definitions = [
            [
                'canonical' => 'market_regime_critical',
                'title' => 'BSCS Critical — opens paused',
                'description' => 'BSCS crossed the cooldown threshold and paused new position openings.',
                'usage_reference' => 'AnalyseBscsJob',
                'default_severity' => 'high',
                'cache_duration' => 0,
            ],
            [
                'canonical' => 'market_regime_recovered',
                'title' => 'BSCS Recovered — opens resumed',
                'description' => 'The BSCS cooldown expired below threshold and new position openings resumed.',
                'usage_reference' => 'AnalyseBscsJob',
                'default_severity' => 'info',
                'cache_duration' => 0,
            ],
            [
                'canonical' => 'market_shock_circuit_breaker',
                'title' => 'Market shock — opens paused',
                'description' => 'The fast market-shock circuit breaker paused new position openings.',
                'usage_reference' => 'DetectMarketShockJob',
                'default_severity' => 'high',
                'cache_duration' => 0,
            ],
            [
                'canonical' => 'market_shock_recovered',
                'title' => 'Market shock cleared — opens resumed',
                'description' => 'The market-shock cooldown expired and new position openings resumed.',
                'usage_reference' => 'AnalyseBscsJob',
                'default_severity' => 'info',
                'cache_duration' => 0,
            ],
            [
                'canonical' => 'trading_guard_paused',
                'title' => 'Trading guard — opens paused',
                'description' => 'The health guard paused new position openings after a system protection fired.',
                'usage_reference' => 'TradingCooldown',
                'default_severity' => 'high',
                'cache_duration' => 0,
            ],
            [
                'canonical' => 'trading_guard_recovered',
                'title' => 'Trading guard recovered — opens resumed',
                'description' => 'The health guard recovered and resumed new position openings.',
                'usage_reference' => 'TradingCooldown',
                'default_severity' => 'info',
                'cache_duration' => 0,
            ],
        ];

        foreach ($definitions as $definition) {
            $timestamp = now();
            $values = [
                ...$definition,
                'detailed_description' => $definition['description'],
                'verified' => true,
                'cache_key' => null,
                'updated_at' => $timestamp,
            ];

            if (! DB::table('notifications')->where('canonical', $definition['canonical'])->exists()) {
                $values['created_at'] = $timestamp;
                $values['is_active'] = true;
            }

            DB::table('notifications')->updateOrInsert(
                ['canonical' => $definition['canonical']],
                $values,
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('notifications')
            ->whereIn('canonical', [
                'market_shock_recovered',
                'trading_guard_paused',
                'trading_guard_recovered',
            ])
            ->delete();
    }
};
