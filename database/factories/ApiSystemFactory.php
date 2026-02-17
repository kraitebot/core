<?php

declare(strict_types=1);

namespace Kraite\Core\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kraite\Core\Models\ApiSystem;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Kraite\Core\Models\ApiSystem>
 */
final class ApiSystemFactory extends Factory
{
    protected $model = ApiSystem::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'is_exchange' => false,
            'name' => fake()->company(),
            'canonical' => fake()->unique()->slug(),
            'recvwindow_margin' => 1000,
            'websocket_class' => null,
        ];
    }

    /**
     * Indicate that the API system is for TAAPI.
     */
    public function taapi(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'name' => 'TAAPI',
                'canonical' => 'taapi',
                'is_exchange' => false,
            ];
        });
    }

    /**
     * Indicate that the API system is an exchange.
     */
    public function exchange(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'is_exchange' => true,
                'timeframes' => ['5m', '1h', '4h', '12h', '1d'],
            ];
        });
    }
}
