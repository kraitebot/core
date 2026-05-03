<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Kucoin;

use InvalidArgumentException;
use Kraite\Core\Abstracts\BaseDataMapper;
use Kraite\Core\Support\ApiDataMappers\Kucoin\ApiRequests\MapsAccountBalanceQuery;
use Kraite\Core\Support\ApiDataMappers\Kucoin\ApiRequests\MapsAccountQuery;
use Kraite\Core\Support\ApiDataMappers\Kucoin\ApiRequests\MapsAccountQueryTrades;
use Kraite\Core\Support\ApiDataMappers\Kucoin\ApiRequests\MapsCancelOrders;
use Kraite\Core\Support\ApiDataMappers\Kucoin\ApiRequests\MapsExchangeInformationQuery;
use Kraite\Core\Support\ApiDataMappers\Kucoin\ApiRequests\MapsKlinesQuery;
use Kraite\Core\Support\ApiDataMappers\Kucoin\ApiRequests\MapsLeverageBracketsQuery;
use Kraite\Core\Support\ApiDataMappers\Kucoin\ApiRequests\MapsMarkPriceQuery;
use Kraite\Core\Support\ApiDataMappers\Kucoin\ApiRequests\MapsOpenOrdersQuery;
use Kraite\Core\Support\ApiDataMappers\Kucoin\ApiRequests\MapsOrderCancel;
use Kraite\Core\Support\ApiDataMappers\Kucoin\ApiRequests\MapsOrderQuery;
use Kraite\Core\Support\ApiDataMappers\Kucoin\ApiRequests\MapsPlaceOrder;
use Kraite\Core\Support\ApiDataMappers\Kucoin\ApiRequests\MapsPositionsQuery;
use Kraite\Core\Support\ApiDataMappers\Kucoin\ApiRequests\MapsServerTimeQuery;
use Kraite\Core\Support\ApiDataMappers\Kucoin\ApiRequests\MapsStopOrder;
use Kraite\Core\Support\ApiDataMappers\Kucoin\ApiRequests\MapsStopOrdersQuery;
use Kraite\Core\Support\ApiDataMappers\Kucoin\ApiRequests\MapsSymbolConfigQuery;
use Kraite\Core\Support\ApiDataMappers\Kucoin\ApiRequests\MapsSymbolMarginType;
use Kraite\Core\Support\ApiDataMappers\Kucoin\ApiRequests\MapsTokenLeverageRatios;
use Kraite\Core\Support\Math;
use SensitiveParameter;

/**
 * KuCoin Futures API Data Mapper.
 *
 * Note: KuCoin Futures does NOT support order modification (MapsOrderModify).
 * Unlike Binance and BitGet which have dedicated edit/modify endpoints,
 * KuCoin requires canceling the existing order and placing a new one.
 * The Spot API has a modify endpoint, but it internally cancels and recreates.
 *
 * @see https://www.kucoin.com/docs/rest/futures-trading/orders/place-order
 */
final class KucoinApiDataMapper extends BaseDataMapper
{
    use MapsAccountBalanceQuery;
    use MapsAccountQuery;
    use MapsAccountQueryTrades;
    use MapsCancelOrders;
    use MapsExchangeInformationQuery;
    use MapsKlinesQuery;
    use MapsLeverageBracketsQuery;
    use MapsMarkPriceQuery;
    use MapsOpenOrdersQuery;
    use MapsOrderCancel;
    use MapsOrderQuery;
    use MapsPlaceOrder;
    use MapsPositionsQuery;
    use MapsServerTimeQuery;
    use MapsStopOrder;
    use MapsStopOrdersQuery;
    use MapsSymbolConfigQuery;
    use MapsSymbolMarginType;
    use MapsTokenLeverageRatios;

    public function long()
    {
        return 'long';
    }

    public function short()
    {
        return 'short';
    }

    public function directionType(string $canonical)
    {
        if ($canonical === 'LONG') {
            return 'long';
        }

        if ($canonical === 'SHORT') {
            return 'short';
        }

        throw new InvalidArgumentException("Invalid KuCoin direction type: {$canonical}");
    }

    public function sideType(string $canonical)
    {
        if ($canonical === 'BUY') {
            return 'buy';
        }

        if ($canonical === 'SELL') {
            return 'sell';
        }

        throw new InvalidArgumentException("Invalid KuCoin side type: {$canonical}");
    }

    /**
     * Returns the well formed base symbol with the quote on it.
     *
     * KuCoin Futures uses format like XBTUSDTM (perpetual) or XBTUSDM (inverse).
     * The 'M' suffix indicates a perpetual contract.
     *
     * Token and quote are stored directly on exchange_symbols.
     */
    public function baseWithQuote(#[SensitiveParameter] string $token, string $quote): string
    {
        // KuCoin uses XBT instead of BTC
        if ($token === 'BTC') {
            $token = 'XBT';
        }

        // KuCoin perpetual format: XBTUSDTM, ETHUSDTM
        return $token.$quote.'M';
    }

    /**
     * Returns an array with an identification of the base and currency
     * quotes, as an array, as example:
     *
     * input: XBTUSDTM
     * returns: ['base' => 'XBT', 'quote' => 'USDT']
     *
     * input: ETHUSDM
     * returns: ['base' => 'ETH', 'quote' => 'USD']
     */
    public function identifyBaseAndQuote(string $symbol): array
    {
        // Remove the 'M' suffix for perpetual contracts
        $symbolPart = preg_replace('/M$/', '', $symbol);

        if ($symbolPart === null) {
            $symbolPart = $symbol;
        }

        // KuCoin Futures primarily uses USDT, USD as quote currencies
        $availableQuoteCurrencies = [
            'USDT', 'USD', 'USDC',
        ];

        foreach ($availableQuoteCurrencies as $quoteCurrency) {
            if (! (str_ends_with(haystack: $symbolPart, needle: $quoteCurrency))) {
                continue;
            }

            $base = str_replace(search: $quoteCurrency, replace: '', subject: $symbolPart);

            return [
                'base' => $base,
                'quote' => $quoteCurrency,
            ];
        }

        throw new InvalidArgumentException("Invalid KuCoin symbol format: {$symbol}");
    }

    /**
     * Returns a canonical order type from KuCoin order data.
     *
     * @param  array<string, mixed>  $order
     */
    public function canonicalOrderType(array $order): string
    {
        $type = mb_strtolower($order['type'] ?? '');
        $stop = $order['stop'] ?? '';
        $stopPrice = (string) ($order['stopPrice'] ?? '0');

        // Check if it's a stop order
        if ($stop !== '' || Math::gt($stopPrice, '0')) {
            return 'STOP_MARKET';
        }

        return match ($type) {
            'market' => 'MARKET',
            'limit' => 'LIMIT',
            default => 'UNKNOWN',
        };
    }
}
