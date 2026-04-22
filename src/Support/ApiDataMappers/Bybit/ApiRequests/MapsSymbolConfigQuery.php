<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Bybit\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Account;
use Kraite\Core\Support\ValueObjects\ApiProperties;

/**
 * Maps Bybit per-symbol account configuration.
 *
 * Bybit returns leverage and tradeMode inline on each position returned by
 * /v5/position/list, so this trait reuses that endpoint and reshapes the
 * response into a normalized, cross-exchange structure.
 *
 * tradeMode: 0 = cross margin, 1 = isolated margin.
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
     * Resolves the positions payload into a normalized shape keyed by symbol.
     *
     * @return array<string, array{symbol: string, leverage: int, marginType: string}>
     */
    public function resolveQuerySymbolConfigResponse(Response $response): array
    {
        $body = json_decode((string) $response->getBody(), associative: true);
        $positions = $body['result']['list'] ?? [];

        return collect(is_array($positions) ? $positions : [])
            ->filter(static function ($position) {
                return is_array($position) && isset($position['symbol']);
            })
            ->map(function (array $position): array {
                $symbol = (string) $position['symbol'];
                if ($symbol !== '') {
                    $parts = $this->identifyBaseAndQuote($symbol);
                    $symbol = $this->baseWithQuote($parts['base'], $parts['quote']);
                }

                $marginType = (int) ($position['tradeMode'] ?? 0) === 1 ? 'ISOLATED' : 'CROSSED';

                return [
                    'symbol' => $symbol,
                    'leverage' => (int) ($position['leverage'] ?? 0),
                    'marginType' => $marginType,
                ];
            })
            ->keyBy('symbol')
            ->all();
    }
}
