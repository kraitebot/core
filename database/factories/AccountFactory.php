<?php

declare(strict_types=1);

namespace Kraite\Core\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\TradeConfiguration;
use Kraite\Core\Models\User;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Kraite\Core\Models\Account>
 */
final class AccountFactory extends Factory
{
    protected $model = Account::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => fake()->uuid(),
            'user_id' => User::factory(),
            'api_system_id' => ApiSystem::factory(),
            'name' => fake()->words(3, true).' Account',
            'trade_configuration_id' => TradeConfiguration::factory(),
            'portfolio_quote' => 'USDT',
            'trading_quote' => 'USDT',
            'margin' => 1000.00,
            'can_trade' => true,
            'market_order_margin_percentage_long' => 0.02,
            'market_order_margin_percentage_short' => 0.02,
            'profit_percentage' => 0.01,
            'margin_ratio_threshold_to_notify' => 0.80,
            'total_limit_orders_filled_to_notify' => 3,
            'stop_market_initial_percentage' => 0.05,
            'total_positions_short' => 0,
            'total_positions_long' => 0,
            'stop_market_wait_minutes' => 5,
            'position_leverage_short' => 1,
            'position_leverage_long' => 1,
            'binance_api_key' => null,
            'binance_api_secret' => null,
            'bybit_api_key' => null,
            'bybit_api_secret' => null,
            'last_report_id' => null,
        ];
    }

    /**
     * Indicate that the account can trade.
     */
    public function canTrade(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'can_trade' => true,
            ];
        });
    }

    /**
     * Indicate that the account cannot trade.
     */
    public function cannotTrade(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'can_trade' => false,
            ];
        });
    }
}
