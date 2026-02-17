<?php

declare(strict_types=1);

namespace Kraite\Core\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Symbol;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Kraite\Core\Models\ExchangeSymbol>
 */
final class ExchangeSymbolFactory extends Factory
{
    protected $model = ExchangeSymbol::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'token' => strtoupper(fake()->lexify('???')),
            'quote' => 'USDT',
            'symbol_id' => null,
            'api_system_id' => ApiSystem::factory(),
            'is_manually_enabled' => true,
            'has_no_indicator_data' => false,
            'has_price_trend_misalignment' => false,
            'has_early_direction_change' => false,
            'has_invalid_indicator_direction' => false,
            'api_statuses' => [],
            'direction' => null,
            'percentage_gap_long' => 8.50,
            'percentage_gap_short' => 9.50,
            'price_precision' => fake()->numberBetween(2, 8),
            'quantity_precision' => fake()->numberBetween(0, 4),
            'min_notional' => fake()->randomFloat(2, 5, 100),
            'delivery_ts_ms' => null,
            'delivery_at' => null,
            'tick_size' => fake()->randomFloat(8, 0.00000001, 0.01),
            'min_price' => fake()->randomFloat(2, 0.01, 1),
            'max_price' => fake()->randomFloat(2, 100000, 1000000),
            'symbol_information' => null,
            'total_limit_orders' => 4,
            'leverage_brackets' => null,
            'indicators_values' => null,
            'limit_quantity_multipliers' => null,
            'disable_on_price_spike_percentage' => 15.00,
            'price_spike_cooldown_hours' => 72,
            'indicators_timeframe' => null,
            'indicators_synced_at' => null,
        ];
    }

    /**
     * State: Active and eligible for trading.
     */
    public function active(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'is_manually_enabled' => true,
            ];
        });
    }

    /**
     * State: TAAPI verified with data available.
     */
    public function taapiVerified(): static
    {
        return $this->state(function (array $attributes) {
            $apiStatuses = $attributes['api_statuses'] ?? [];
            $apiStatuses['taapi_verified'] = true;
            $apiStatuses['has_taapi_data'] = true;

            return [
                'api_statuses' => $apiStatuses,
            ];
        });
    }

    /**
     * State: TAAPI verified but no data available.
     */
    public function noTaapiData(): static
    {
        return $this->state(function (array $attributes) {
            $apiStatuses = $attributes['api_statuses'] ?? [];
            $apiStatuses['taapi_verified'] = true;
            $apiStatuses['has_taapi_data'] = false;

            return [
                'has_no_indicator_data' => true,
                'api_statuses' => $apiStatuses,
            ];
        });
    }

    /**
     * State: Long direction.
     */
    public function long(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'direction' => 'LONG',
            ];
        });
    }

    /**
     * State: Short direction.
     */
    public function short(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'direction' => 'SHORT',
            ];
        });
    }
}
