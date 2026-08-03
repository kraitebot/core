<?php

declare(strict_types=1);

namespace Kraite\Core\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kraite\Core\Models\Candle;
use Kraite\Core\Models\ExchangeSymbol;

/**
 * @extends Factory<Candle>
 */
final class CandleFactory extends Factory
{
    protected $model = Candle::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        $timestamp = fake()->unixTime();

        return [
            'exchange_symbol_id' => ExchangeSymbol::factory(),
            'timeframe' => fake()->randomElement(['1h', '4h', '1d']),
            'timestamp' => $timestamp,
            'candle_time_utc' => date('Y-m-d H:i:s', $timestamp),
            'candle_time_local' => date('Y-m-d H:i:s', $timestamp),
            'open' => fake()->randomFloat(8, 0.0001, 100000),
            'high' => fake()->randomFloat(8, 0.0001, 100000),
            'low' => fake()->randomFloat(8, 0.0001, 100000),
            'close' => fake()->randomFloat(8, 0.0001, 100000),
            'volume' => fake()->randomFloat(2, 0, 1000000000),
        ];
    }

    /**
     * State: 1h timeframe.
     */
    public function hourly(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'timeframe' => '1h',
            ];
        });
    }

    /**
     * State: 4h timeframe.
     */
    public function fourHourly(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'timeframe' => '4h',
            ];
        });
    }

    /**
     * State: 1d timeframe.
     */
    public function daily(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'timeframe' => '1d',
            ];
        });
    }

    /**
     * Set a specific timestamp.
     */
    public function atTimestamp(int $timestamp): static
    {
        return $this->state(function (array $attributes) use ($timestamp) {
            return [
                'timestamp' => $timestamp,
                'candle_time' => date('Y-m-d H:i:s', $timestamp),
            ];
        });
    }
}
