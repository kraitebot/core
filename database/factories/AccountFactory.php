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
            'margin_percentage_long' => 5.00,
            'margin_percentage_short' => 5.00,
            'profit_percentage' => 0.01,
            'total_limit_orders_filled_to_notify' => 3,
            'stop_market_initial_percentage' => 0.05,
            'total_positions_short' => 0,
            'total_positions_long' => 0,
            'position_leverage_short' => 1,
            'position_leverage_long' => 1,
            'binance_api_key' => null,
            'binance_api_secret' => null,
            'bybit_api_key' => null,
            'bybit_api_secret' => null,
            'last_report_id' => null,
            // One-way is the popular default for new traders. Hedge-mode
            // accounts get this explicitly via the `hedgeMode()` factory
            // state. Live accounts auto-correct via the reactive flip on
            // -4061 / equivalent if the wrong default ever leaks.
            'on_hedge_mode' => false,
            // Both default to false (Kraite-exclusive account) — matches
            // the migration default. Tests opting into "user-also-trades"
            // scenarios set these explicitly.
            'allow_other_positions' => false,
            'allow_other_orders' => false,
        ];
    }

    /**
     * Indicate that the account is in hedge mode (dual position side on
     * Binance, positionIdx=1|2 on Bybit, etc.).
     */
    public function hedgeMode(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'on_hedge_mode' => true,
            ];
        });
    }

    /**
     * Indicate that the account is in one-way mode (single position side).
     */
    public function oneWayMode(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'on_hedge_mode' => false,
            ];
        });
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
