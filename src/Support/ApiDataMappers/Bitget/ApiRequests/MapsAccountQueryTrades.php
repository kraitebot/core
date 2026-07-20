<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\ApiDataMappers\Bitget\BitgetProductContext;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsAccountQueryTrades
{
    public function prepareQueryTokenTradesProperties(Position $position, ?string $orderId = null): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $position);
        $properties->set(
            'options.productType',
            BitgetProductContext::fromQuote($position->exchangeSymbol->quote)->productType
        );
        $properties->set('options.symbol', (string) $position->exchangeSymbol->asset);

        if ($orderId !== null) {
            $properties->set('options.orderId', $orderId);
        }

        return $properties;
    }

    /**
     * Resolves BitGet order fills response.
     *
     * BitGet V2 response structure:
     * {
     *     "code": "00000",
     *     "data": {
     *         "fillList": [
     *             {
     *                 "tradeId": "123456",
     *                 "symbol": "BTCUSDT",
     *                 "orderId": "789",
     *                 "price": "40000",
     *                 "baseVolume": "0.001",
     *                 "feeDetail": {...},
     *                 "side": "buy",
     *                 "profit": "0",
     *                 "tradeSide": "open",
     *                 "cTime": "1627116936176"
     *             }
     *         ],
     *         "endId": "123456"
     *     }
     * }
     */
    /**
     * Normalise Bitget's `fillList` so the cross-exchange
     * `extractClosingPriceFromTrades` (Binance-shaped) can extract the
     * correct closing fill for both LONG and SHORT positions.
     *
     * Two adjustments:
     *
     *   1. **Side flip on close fills.** Bitget hedge mode reports the
     *      ORIGINAL opening side on every fill (`side: "buy"` for LONG,
     *      `side: "sell"` for SHORT) regardless of open/close — the
     *      open/close discriminator is `tradeSide`. Binance's extractor
     *      assumes `side` flips on close (LONG closed by SELL). We flip
     *      `side` on `tradeSide=close` fills so the extractor's
     *      `side === closeSide` check matches. Open fills are left as-is.
     *
     *      This normalization applies only to Classic v2 hedge fills. Classic
     *      one-way and Unified v3 fills already report the execution side.
     *
     *   2. **Reverse to oldest-first.** Bitget returns NEWEST-first;
     *      Binance returns OLDEST-first. The extractor does its own
     *      `array_reverse` expecting oldest-first input — Bitget's
     *      newest-first response double-reversed and made the loop pick
     *      the OLDEST matching close instead of the NEWEST (the relevant
     *      one for the position we just closed). Output is oldest-first
     *      to match Binance's contract.
     *
     * Other fields are passed through verbatim.
     */
    public function resolveQueryTradeResponse(Response $response, ?Position $position = null): array
    {
        $body = json_decode((string) $response->getBody(), associative: true);
        $data = $body['data'] ?? [];
        $isUnified = is_array($data) && array_key_exists('list', $data);
        $fills = $isUnified ? ($data['list'] ?? []) : ($data['fillList'] ?? []);

        if (! is_array($fills) || $fills === []) {
            return [];
        }

        if ($isUnified && $position !== null) {
            $symbol = mb_strtoupper((string) $position->parsed_trading_pair);
            $direction = mb_strtolower((string) $position->direction);
            $fills = array_values(array_filter(
                $fills,
                static function (array $fill) use ($direction, $position, $symbol): bool {
                    if (mb_strtoupper((string) ($fill['symbol'] ?? '')) !== $symbol) {
                        return false;
                    }

                    if (! $position->account->isHedgeMode()) {
                        return true;
                    }

                    $fillDirection = mb_strtolower((string) ($fill['posSide'] ?? ''));

                    if ($fillDirection === '') {
                        $side = mb_strtolower((string) ($fill['side'] ?? ''));
                        $tradeSide = mb_strtolower((string) ($fill['tradeSide'] ?? ''));
                        $fillDirection = match ([$tradeSide, $side]) {
                            ['open', 'buy'], ['close', 'sell'] => 'long',
                            ['open', 'sell'], ['close', 'buy'] => 'short',
                            default => '',
                        };
                    }

                    return $fillDirection === $direction;
                }
            ));
        }

        $shouldFlipClassicCloseSide = ! $isUnified
            && ($position === null || $position->account->isHedgeMode());

        $normalised = array_map(static function (array $fill) use ($isUnified, $shouldFlipClassicCloseSide): array {
            if ($isUnified) {
                $fill['tradeId'] ??= $fill['execId'] ?? '';
                $fill['price'] ??= $fill['execPrice'] ?? '0';
                $fill['baseVolume'] ??= $fill['execQty'] ?? '0';
                $fill['cTime'] ??= $fill['createdTime'] ?? '0';
                $fill['profit'] ??= $fill['execPnl'] ?? '0';

                return $fill;
            }

            $tradeSide = mb_strtolower((string) ($fill['tradeSide'] ?? ''));
            if ($shouldFlipClassicCloseSide && $tradeSide === 'close') {
                $original = mb_strtolower((string) ($fill['side'] ?? ''));
                if ($original === 'buy') {
                    $fill['side'] = 'sell';
                } elseif ($original === 'sell') {
                    $fill['side'] = 'buy';
                }
            }

            return $fill;
        }, $fills);

        return array_values(array_reverse($normalised));
    }
}
