<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Kucoin\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Account;
use Kraite\Core\Support\ValueObjects\ApiProperties;

/**
 * Maps KuCoin per-symbol account configuration.
 *
 * KuCoin returns realLeverage and crossMode inline on each position returned
 * by /api/v1/positions, so this trait reuses that endpoint and reshapes the
 * response into a normalized, cross-exchange structure.
 *
 * crossMode: true = cross margin, false = isolated margin.
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
        $positions = $body['data'] ?? [];

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

                $marginType = ($position['crossMode'] ?? false) === true ? 'CROSSED' : 'ISOLATED';

                return [
                    'symbol' => $symbol,
                    'leverage' => (int) round((float) ($position['realLeverage'] ?? 0)),
                    'marginType' => $marginType,
                ];
            })
            ->keyBy('symbol')
            ->all();
    }
}
