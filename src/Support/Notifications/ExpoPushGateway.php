<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Notifications;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Kraite\Core\Models\AppPushDevice;
use RuntimeException;
use Throwable;

final class ExpoPushGateway
{
    private const BATCH_SIZE = 100;

    /**
     * @param  Collection<int, AppPushDevice>  $devices
     * @param  array<string, mixed>  $message
     * @return array{status: string, device_count: int, tickets: list<array<string, mixed>>}
     */
    public function send(Collection $devices, array $message): array
    {
        if ($devices->isEmpty() || ! Config::boolean('kraite.app_push.enabled', true)) {
            return [
                'status' => $devices->isEmpty() ? 'stored' : 'disabled',
                'device_count' => $devices->count(),
                'tickets' => [],
            ];
        }

        $tickets = [];

        foreach ($devices->chunk(self::BATCH_SIZE) as $deviceBatch) {
            $payloads = $deviceBatch
                ->map(static fn (AppPushDevice $device): array => [
                    ...$message,
                    'to' => $device->expo_push_token,
                    'badge' => $device->incrementUnreadCount(),
                ])
                ->values()
                ->all();

            $response = $this->request()
                ->withBody(json_encode($payloads, JSON_THROW_ON_ERROR), 'application/json')
                ->post(Config::string('kraite.app_push.endpoint'))
                ->throw();

            $batchTickets = $response->json('data');
            if (! is_array($batchTickets) || count($batchTickets) !== $deviceBatch->count()) {
                throw new RuntimeException('Expo returned an invalid push-ticket response.');
            }

            foreach ($deviceBatch->values() as $index => $device) {
                $ticket = $batchTickets[$index] ?? null;
                if (! is_array($ticket)) {
                    throw new RuntimeException('Expo returned an invalid push ticket.');
                }

                $error = data_get($ticket, 'details.error');
                if ($error === 'DeviceNotRegistered') {
                    $device->update(['disabled_at' => now()]);
                }

                $tickets[] = [
                    'device_id' => $device->id,
                    'status' => is_string($ticket['status'] ?? null) ? $ticket['status'] : 'error',
                    'id' => is_string($ticket['id'] ?? null) ? $ticket['id'] : null,
                    'error' => is_string($error) ? $error : null,
                    'message' => is_string($ticket['message'] ?? null) ? $ticket['message'] : null,
                ];
            }
        }

        return [
            'status' => 'accepted',
            'device_count' => $devices->count(),
            'tickets' => $tickets,
        ];
    }

    private function request(): PendingRequest
    {
        $request = Http::acceptJson()
            ->asJson()
            ->connectTimeout(Config::integer('kraite.app_push.connect_timeout_seconds', 3))
            ->timeout(Config::integer('kraite.app_push.timeout_seconds', 10))
            ->retry(
                [200, 500],
                when: static fn (Throwable $exception): bool => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->serverError()),
                throw: false,
            );

        $accessToken = Config::get('kraite.app_push.access_token');

        return is_string($accessToken) && $accessToken !== ''
            ? $request->withToken($accessToken)
            : $request;
    }
}
