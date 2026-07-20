<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Account;
use Kraite\Core\Support\ApiDataMappers\Bitget\BitgetProductContext;
use Kraite\Core\Support\ValueObjects\ApiProperties;

/**
 * Maps BitGet per-symbol account configuration.
 *
 * BitGet returns leverage and marginMode inline on each position returned by
 * /api/v2/mix/position/all-position, so this trait reuses that endpoint and
 * reshapes the response into a normalized, cross-exchange structure.
 */
trait MapsSymbolConfigQuery
{
    public function prepareQuerySymbolConfigProperties(Account $account): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $account);

        $properties->set(
            'options.productType',
            BitgetProductContext::fromQuote($account->trading_quote)->productType
        );

        return $properties;
    }

    /**
     * Resolves the positions payload into a normalized shape keyed by symbol.
     *
     * BitGet reports marginMode as "crossed" or "isolated"; we normalize to
     * "CROSSED" / "ISOLATED" for parity with Binance.
     *
     * @return array<string, array{symbol: string, leverage: int, marginType: string}>
     */
    public function resolveQuerySymbolConfigResponse(Response $response): array
    {
        $body = json_decode((string) $response->getBody(), associative: true);
        $data = $body['data'] ?? [];
        $positions = is_array($data)
            ? ($data['symbolConfigList'] ?? $data)
            : [];

        return collect(is_array($positions) ? $positions : [])
            ->filter(static function ($position) {
                return is_array($position) && isset($position['symbol']);
            })
            ->map(static function (array $position): array {
                $marginMode = mb_strtoupper((string) ($position['marginMode'] ?? ''));
                if ($marginMode === 'CROSS') {
                    $marginMode = 'CROSSED';
                }

                return [
                    'symbol' => (string) $position['symbol'],
                    'leverage' => (int) ($position['leverage']
                        ?? $position['longLeverage']
                        ?? $position['shortLeverage']
                        ?? 0),
                    'marginType' => $marginMode,
                ];
            })
            ->keyBy('symbol')
            ->all();
    }
}
