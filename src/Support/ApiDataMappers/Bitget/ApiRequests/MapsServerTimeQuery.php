<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests;

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
     * Resolves BitGet server time response.
     *
     * BitGet V2 response structure:
     * {
     *     "code": "00000",
     *     "msg": "success",
     *     "requestTime": 1627116936176,
     *     "data": {
     *         "serverTime": "1627116936176"
     *     }
     * }
     *
     * The serverTime field is timestamp in milliseconds.
     */
    public function resolveServerTimeResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);

        // BitGet returns timestamp in milliseconds in data.serverTime
        $timestampMs = $data['data']['serverTime'] ?? $data['requestTime'] ?? null;

        if ($timestampMs === null) {
            return [
                'timestamp_ms' => 0,
                'timestamp_sec' => 0,
                'datetime_utc' => null,
                'datetime_app' => null,
            ];
        }

        // Ensure it's an integer
        $timestampMs = (int) $timestampMs;

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
