<?php

declare(strict_types=1);

namespace Kraite\Core\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kraite\Core\Enums\NotificationSeverity;
use Kraite\Core\Models\Notification;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Kraite\Core\Models\Notification>
 */
final class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'canonical' => fake()->unique()->slug(),
            'title' => fake()->sentence(3),
            'description' => fake()->sentence(10),
            'detailed_description' => fake()->paragraph(),
            'usage_reference' => null,
            'default_severity' => fake()->randomElement(NotificationSeverity::cases()),
            'verified' => true,
        ];
    }

    /**
     * Indicate that the notification is for server rate limit exceeded.
     */
    public function serverRateLimitExceeded(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'canonical' => 'server_rate_limit_exceeded',
                'title' => 'Rate Limit Exceeded',
                'description' => 'Sent when exchange API returns 429 (rate limit)',
                'default_severity' => NotificationSeverity::High,
                'verified' => true,
                'cache_duration' => 600,
                'cache_key' => ['api_system', 'account', 'server'],
            ];
        });
    }

    /**
     * Indicate that the notification is for server IP forbidden (418 IP ban).
     *
     * @deprecated Use specific states: serverIpNotWhitelisted, serverIpRateLimited, serverIpBanned, serverAccountBlocked
     */
    public function serverIpForbidden(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'canonical' => 'server_ip_forbidden',
                'title' => 'Server IP Forbidden',
                'description' => 'Sent when server/IP is forbidden from accessing exchange API (HTTP 418 IP ban)',
                'default_severity' => NotificationSeverity::Critical,
                'verified' => true,
                'cache_duration' => 60,
                'cache_key' => ['api_system', 'server'],
            ];
        });
    }

    /**
     * Indicate that the notification is for IP not whitelisted (USER notification).
     */
    public function serverIpNotWhitelisted(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'canonical' => 'server_ip_not_whitelisted',
                'title' => 'Server IP Not Whitelisted',
                'description' => 'Sent when user\'s API key requires the server IP to be whitelisted',
                'default_severity' => NotificationSeverity::High,
                'verified' => true,
                'cache_duration' => 3600,
                'cache_key' => ['account_id', 'ip_address'],
            ];
        });
    }

    /**
     * Indicate that the notification is for IP rate limited (ADMIN notification).
     */
    public function serverIpRateLimited(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'canonical' => 'server_ip_rate_limited',
                'title' => 'Server IP Rate Limited',
                'description' => 'Sent when server IP is temporarily rate-limited by exchange',
                'default_severity' => NotificationSeverity::High,
                'verified' => true,
                'cache_duration' => 300,
                'cache_key' => ['api_system', 'ip_address'],
            ];
        });
    }

    /**
     * Indicate that the notification is for IP permanently banned (ADMIN notification).
     */
    public function serverIpBanned(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'canonical' => 'server_ip_banned',
                'title' => 'Server IP Permanently Banned',
                'description' => 'Sent when server IP is permanently banned by exchange',
                'default_severity' => NotificationSeverity::Critical,
                'verified' => true,
                'cache_duration' => 3600,
                'cache_key' => ['api_system', 'ip_address'],
            ];
        });
    }

    /**
     * Indicate that the notification is for account blocked (USER notification).
     */
    public function serverAccountBlocked(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'canonical' => 'server_account_blocked',
                'title' => 'Account API Access Blocked',
                'description' => 'Sent when user\'s exchange account API access is blocked',
                'default_severity' => NotificationSeverity::Critical,
                'verified' => true,
                'cache_duration' => 3600,
                'cache_key' => ['account_id', 'api_system'],
            ];
        });
    }

    /**
     * Indicate that the notification is for exchange symbol with no TAAPI data.
     */
    public function exchangeSymbolNoTaapiData(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'canonical' => 'exchange_symbol_no_taapi_data',
                'title' => 'Exchange Symbol Deactivated - No TAAPI Data',
                'description' => 'Sent when an exchange symbol is automatically deactivated due to consistent TAAPI data failures',
                'default_severity' => NotificationSeverity::Medium,
                'verified' => true,
                'cache_duration' => 600,
                'cache_key' => ['exchange_symbol', 'exchange'],
            ];
        });
    }

    /**
     * Indicate that the notification is for token delisting.
     */
    public function tokenDelisting(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'canonical' => 'token_delisting',
                'title' => 'Token Delisting Detected',
                'description' => 'Sent when a token delisting is detected (contract rollover for Binance, perpetual delisting for Bybit)',
                'default_severity' => NotificationSeverity::High,
                'verified' => true,
                'cache_duration' => 600,
                'cache_key' => ['exchange_symbol'],
            ];
        });
    }

    /**
     * Indicate that the notification is unverified.
     */
    public function unverified(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'verified' => false,
            ];
        });
    }
}
