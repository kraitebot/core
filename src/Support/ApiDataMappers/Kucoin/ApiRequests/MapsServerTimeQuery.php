<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Kucoin\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Carbon;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsServerTimeQuery
{
    public function prepareServerTimeProperties(): ApiProperties
    {
        return new ApiProperties;
    }

    /**
     * Resolves KuCoin server time response.
     *
     * KuCoin Futures response structure:
     * {
     *     "code": "200000",
     *     "data": 1702398467892
     * }
     *
     * The data field is timestamp in milliseconds.
     */
    public function resolveServerTimeResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);

        // KuCoin returns timestamp in milliseconds
        $timestampMs = $data['data'] ?? null;

        if ($timestampMs === null) {
            return [
                'timestamp_ms' => 0,
                'timestamp_sec' => 0,
                'datetime_utc' => null,
                'datetime_app' => null,
            ];
        }

        // Convert milliseconds to Carbon
        $carbonUtc = Carbon::createFromTimestampMs($timestampMs, 'UTC');
        $carbonApp = $carbonUtc->clone()->setTimezone(config('app.timezone'));

        return [
            'timestamp_ms' => $timestampMs,
            'timestamp_sec' => (int) ($timestampMs / 1000),
            'datetime_utc' => $carbonUtc->toDateTimeString(),
            'datetime_app' => $carbonApp->toDateTimeString(),
        ];
    }
}
