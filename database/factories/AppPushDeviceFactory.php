<?php

declare(strict_types=1);

namespace Kraite\Core\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Kraite\Core\Models\AppPushDevice;
use Kraite\Core\Models\User;

/**
 * @extends Factory<AppPushDevice>
 */
final class AppPushDeviceFactory extends Factory
{
    protected $model = AppPushDevice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $token = 'ExpoPushToken['.Str::random(22).']';

        return [
            'user_id' => User::factory(),
            'expo_push_token' => $token,
            'token_hash' => hash('sha256', $token),
            'platform' => 'ios',
            'device_name' => 'Kraite iPhone',
            'app_version' => '0.9.0',
            'last_registered_at' => now(),
            'disabled_at' => null,
        ];
    }
}
