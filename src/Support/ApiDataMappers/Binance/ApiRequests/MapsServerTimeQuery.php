<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Binance\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Carbon;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsServerTimeQuery
{
    public function prepareServerTimeProperties(): ApiProperties
    {
        return new ApiProperties;
    }

    public function resolveServerTimeResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);

        // Binance returns: {"serverTime": 1499827319559}
        $timestampMs = (int) ($data['serverTime'] ?? 0);
        $timestampSec = (int) ($timestampMs / 1000);

        // Create Carbon instances for human-readable datetimes
        $carbonUtc = Carbon::createFromTimestampMs($timestampMs, 'UTC');
        $carbonApp = Carbon::createFromTimestampMs($timestampMs, config('app.timezone'));

        return [
            'timestamp_ms' => $timestampMs,
            'timestamp_sec' => $timestampSec,
            'datetime_utc' => $carbonUtc->toDateTimeString(),
            'datetime_app' => $carbonApp->toDateTimeString(),
        ];
    }
}
