<?php

declare(strict_types=1);

namespace Kraite\Core\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\Position;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Kraite\Core\Models\Position>
 */
final class PositionFactory extends Factory
{
    protected $model = Position::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'uuid' => fake()->uuid(),
            'account_id' => Account::factory(),
            'exchange_symbol_id' => null,
            'parsed_trading_pair' => null,
            'status' => 'new',
            'direction' => null,
            'opened_at' => null,
            'closed_at' => null,
            'was_waped' => false,
            'waped_at' => null,
            'was_fast_traded' => false,
            'total_limit_orders' => null,
            'opening_price' => null,
            'margin' => null,
            'quantity' => null,
            'first_profit_price' => null,
            'closing_price' => null,
            'indicators_values' => null,
            'indicators_timeframe' => null,
            'leverage' => null,
            'profit_percentage' => null,
            'error_message' => null,
        ];
    }

    /**
     * State: New position with no token assigned yet (unassigned slot).
     */
    public function unassigned(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'new',
                'exchange_symbol_id' => null,
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

    /**
     * State: Fast-traded position (closed quickly with profit).
     */
    public function fastTraded(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'closed',
                'was_fast_traded' => true,
                'closed_at' => now()->subMinutes(5),
            ];
        });
    }

    /**
     * State: Closed position.
     */
    public function closed(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'closed',
                'closed_at' => now()->subMinutes(30),
            ];
        });
    }

    /**
     * State: Opened position.
     */
    public function opened(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'opened',
                'opened_at' => now()->subHours(1),
            ];
        });
    }
}
