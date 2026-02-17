<?php

declare(strict_types=1);

namespace Kraite\Core\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kraite\Core\Models\Symbol;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Kraite\Core\Models\Symbol>
 */
final class SymbolFactory extends Factory
{
    protected $model = Symbol::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'token' => strtoupper(fake()->unique()->bothify('???##')),
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'site_url' => fake()->url(),
            'image_url' => fake()->imageUrl(),
            'cmc_id' => fake()->numberBetween(1, 999999),
            'cmc_ranking' => fake()->numberBetween(1, 10000),
            'is_stable_coin' => false,
        ];
    }

    /**
     * State: Bitcoin symbol.
     */
    public function bitcoin(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'token' => 'BTC',
                'name' => 'Bitcoin',
                'description' => 'The first and largest cryptocurrency',
                'cmc_id' => 1,
                'cmc_ranking' => 1,
                'is_stable_coin' => false,
            ];
        });
    }

    /**
     * State: Ethereum symbol.
     */
    public function ethereum(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'token' => 'ETH',
                'name' => 'Ethereum',
                'description' => 'Smart contract platform',
                'cmc_id' => 1027,
                'cmc_ranking' => 2,
                'is_stable_coin' => false,
            ];
        });
    }

    /**
     * State: Stablecoin symbol.
     */
    public function stablecoin(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'is_stable_coin' => true,
            ];
        });
    }

    /**
     * State: USDT stablecoin.
     */
    public function usdt(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'token' => 'USDT',
                'name' => 'Tether USDt',
                'description' => 'USD-pegged stablecoin',
                'cmc_id' => 825,
                'cmc_ranking' => 3,
                'is_stable_coin' => true,
            ];
        });
    }
}
