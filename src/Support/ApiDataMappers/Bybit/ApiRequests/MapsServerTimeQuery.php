<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Bybit\ApiRequests;

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

        // Bybit returns: {"retCode": 0, "retMsg": "OK", "result": {"timeSecond": "1688639403", "timeNano": "1688639403423213947"}, "time": 1688639403423}
        // Extract from result.timeNano or time field (time is already in milliseconds)
        $timestampMs = (int) ($data['time'] ?? 0);
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
