<?php

declare(strict_types=1);

namespace Kraite\Core\Notifications\Channels;

use Illuminate\Foundation\Auth\User as AuthenticatableUser;
use Illuminate\Notifications\Notification;
use Kraite\Core\Models\AppPushDevice;
use Kraite\Core\Notifications\AlertNotification;
use Kraite\Core\Support\Notifications\ExpoPushGateway;

final class AppPushChannel
{
    public function __construct(
        private readonly ExpoPushGateway $gateway,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function send(object $notifiable, Notification $notification): array
    {
        if (! $notification instanceof AlertNotification) {
            return [
                'status' => 'stored',
                'device_count' => 0,
                'tickets' => [],
            ];
        }

        if (! $notifiable instanceof AuthenticatableUser) {
            return [
                'status' => 'stored',
                'device_count' => 0,
                'tickets' => [],
            ];
        }

        $userId = $notifiable->getAuthIdentifier();
        if (! is_int($userId) && ! (is_string($userId) && ctype_digit($userId))) {
            return [
                'status' => 'stored',
                'device_count' => 0,
                'tickets' => [],
            ];
        }

        $devices = AppPushDevice::query()
            ->active()
            ->where('user_id', (int) $userId)
            ->orderBy('id')
            ->get();

        return $this->gateway->send($devices, $notification->toAppPush($notifiable));
    }
}
