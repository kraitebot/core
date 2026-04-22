<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Binance\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Account;
use Kraite\Core\Support\ValueObjects\ApiProperties;

/**
 * Maps Binance per-symbol account configuration query.
 *
 * Since /fapi/v3/positionRisk stopped returning `leverage` and `marginType`,
 * /fapi/v1/symbolConfig is the source of truth for those per-symbol values.
 *
 * Raw response shape:
 * [
 *     {
 *         "symbol": "LINKUSDT",
 *         "marginType": "CROSSED",
 *         "isAutoAddMargin": "false",
 *         "leverage": 20,
 *         "maxNotionalValue": "1000000"
 *     }
 * ]
 *
 * @see https://developers.binance.com/docs/derivatives/usds-margined-futures/account/rest-api/Symbol-Configuration
 */
trait MapsSymbolConfigQuery
{
    public function prepareQuerySymbolConfigProperties(Account $account): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $account);

        return $properties;
    }

    /**
     * Resolves the response into a normalized, cross-exchange shape keyed by symbol.
     *
     * @return array<string, array{symbol: string, leverage: int, marginType: string}>
     */
    public function resolveQuerySymbolConfigResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);

        return collect(is_array($data) ? $data : [])
            ->filter(static function ($config) {
                return is_array($config) && isset($config['symbol']);
            })
            ->map(static function (array $config): array {
                return [
                    'symbol' => (string) $config['symbol'],
                    'leverage' => (int) ($config['leverage'] ?? 0),
                    'marginType' => mb_strtoupper((string) ($config['marginType'] ?? '')),
                ];
            })
            ->keyBy('symbol')
            ->all();
    }
}
