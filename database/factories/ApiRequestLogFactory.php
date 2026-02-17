<?php

declare(strict_types=1);

namespace Kraite\Core\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kraite\Core\Models\ApiRequestLog;
use Kraite\Core\Models\ApiSystem;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Kraite\Core\Models\ApiRequestLog>
 */
final class ApiRequestLogFactory extends Factory
{
    protected $model = ApiRequestLog::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = now()->subSeconds(fake()->numberBetween(1, 300));
        $duration = fake()->numberBetween(50, 2000);
        $completedAt = $startedAt->copy()->addMilliseconds($duration);

        return [
            'api_system_id' => ApiSystem::factory(),
            'account_id' => null,
            'http_response_code' => 200,
            'http_method' => fake()->randomElement(['GET', 'POST', 'PUT', 'DELETE']),
            'path' => '/'.fake()->word().'/'.fake()->word(),
            'hostname' => gethostname(),
            'started_at' => $startedAt,
            'completed_at' => $completedAt,
            'duration' => $duration,
            'payload' => null,
            'http_headers_sent' => null,
            'response' => null,
            'http_headers_returned' => null,
            'debug_data' => null,
            'error_message' => null,
            'relatable_type' => null,
            'relatable_id' => null,
        ];
    }

    /**
     * Indicate that the request returned a 429 (rate limit) error.
     */
    public function rateLimited(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'http_response_code' => 429,
                'error_message' => 'Rate limit exceeded',
            ];
        });
    }

    /**
     * Indicate that the request returned a 403 (forbidden) error.
     */
    public function forbidden(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'http_response_code' => 403,
                'error_message' => 'Forbidden',
            ];
        });
    }

    /**
     * Indicate that the request returned a 500 (server error) error.
     */
    public function serverError(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'http_response_code' => 500,
                'error_message' => 'Internal server error',
            ];
        });
    }

    /**
     * Indicate that the request was successful.
     */
    public function successful(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'http_response_code' => 200,
                'error_message' => null,
            ];
        });
    }
}
