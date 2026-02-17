<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Proxies;

use Kraite\Core\Support\Throttlers\BinanceThrottler;
use Kraite\Core\Support\Throttlers\BitgetThrottler;
use Kraite\Core\Support\Throttlers\BybitThrottler;
use Kraite\Core\Support\Throttlers\CoinmarketCapThrottler;
use Kraite\Core\Support\Throttlers\KucoinThrottler;
use Kraite\Core\Support\Throttlers\TaapiThrottler;

/**
 * ApiThrottlerProxy
 *
 * Maps API systems to their corresponding throttler classes.
 * Returns null for APIs without throttlers (graceful degradation).
 */
final class ApiThrottlerProxy
{
    /**
     * Get the throttler class for a given API system.
     *
     * @param  string  $apiSystem  The API system canonical name ('taapi', 'coinmarketcap', 'binance', 'bybit', etc.)
     * @return string|null The fully-qualified throttler class name, or null if no throttler exists
     */
    public static function getThrottler(string $apiSystem): ?string
    {
        return match ($apiSystem) {
            'taapi' => TaapiThrottler::class,
            'coinmarketcap' => CoinmarketCapThrottler::class,
            'binance' => BinanceThrottler::class,
            'bybit' => BybitThrottler::class,
            'kucoin' => KucoinThrottler::class,
            'bitget' => BitgetThrottler::class,
            default => null, // No throttler = no rate limiting
        };
    }
}
