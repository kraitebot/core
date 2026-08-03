<?php

declare(strict_types=1);

namespace Kraite\Core\Support;

use GuzzleHttp\Exception\RequestException;
use Throwable;

final class TaapiNoDataException
{
    /** @var list<string> */
    private const PATTERNS = [
        'invalid symbol',
        'no candles',
        'no candle data',
    ];

    public static function matches(Throwable $exception): bool
    {
        if (! $exception instanceof RequestException) {
            return false;
        }

        $response = $exception->getResponse();

        if ($response === null || ! in_array($response->getStatusCode(), [400, 404], true)) {
            return false;
        }

        $body = mb_strtolower((string) $response->getBody());

        foreach (self::PATTERNS as $pattern) {
            if (str_contains($body, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
