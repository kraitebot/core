<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Bybit;

use InvalidArgumentException;
use Kraite\Core\Abstracts\BaseDataMapper;
use Kraite\Core\Support\ApiDataMappers\Bybit\ApiRequests\MapsAccountBalanceQuery;
use Kraite\Core\Support\ApiDataMappers\Bybit\ApiRequests\MapsAccountQuery;
use Kraite\Core\Support\ApiDataMappers\Bybit\ApiRequests\MapsAccountQueryTrades;
use Kraite\Core\Support\ApiDataMappers\Bybit\ApiRequests\MapsCancelOrders;
use Kraite\Core\Support\ApiDataMappers\Bybit\ApiRequests\MapsExchangeInformationQuery;
use Kraite\Core\Support\ApiDataMappers\Bybit\ApiRequests\MapsKlinesQuery;
use Kraite\Core\Support\ApiDataMappers\Bybit\ApiRequests\MapsLeverageBracketsQuery;
use Kraite\Core\Support\ApiDataMappers\Bybit\ApiRequests\MapsMarkPriceQuery;
use Kraite\Core\Support\ApiDataMappers\Bybit\ApiRequests\MapsOpenOrdersQuery;
use Kraite\Core\Support\ApiDataMappers\Bybit\ApiRequests\MapsOrderCancel;
use Kraite\Core\Support\ApiDataMappers\Bybit\ApiRequests\MapsOrderModify;
use Kraite\Core\Support\ApiDataMappers\Bybit\ApiRequests\MapsOrderQuery;
use Kraite\Core\Support\ApiDataMappers\Bybit\ApiRequests\MapsPlaceOrder;
use Kraite\Core\Support\ApiDataMappers\Bybit\ApiRequests\MapsPositionsQuery;
use Kraite\Core\Support\ApiDataMappers\Bybit\ApiRequests\MapsServerTimeQuery;
use Kraite\Core\Support\ApiDataMappers\Bybit\ApiRequests\MapsStopOrdersQuery;
use Kraite\Core\Support\ApiDataMappers\Bybit\ApiRequests\MapsSymbolConfigQuery;
use Kraite\Core\Support\ApiDataMappers\Bybit\ApiRequests\MapsSymbolMarginType;
use Kraite\Core\Support\ApiDataMappers\Bybit\ApiRequests\MapsTokenLeverageRatios;
use SensitiveParameter;

final class BybitApiDataMapper extends BaseDataMapper
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
    use MapsOrderModify;
    use MapsOrderQuery;
    use MapsPlaceOrder;
    use MapsPositionsQuery;
    use MapsServerTimeQuery;
    use MapsStopOrdersQuery;
    use MapsSymbolConfigQuery;
    use MapsSymbolMarginType;
    use MapsTokenLeverageRatios;

    public function long()
    {
        return 'LONG';
    }

    public function short()
    {
        return 'SHORT';
    }

    public function directionType(string $canonical)
    {
        if ($canonical === 'LONG') {
            return 'LONG';
        }

        if ($canonical === 'SHORT') {
            return 'SHORT';
        }

        throw new InvalidArgumentException("Invalid Bybit direction type: {$canonical}");
    }

    public function sideType(string $canonical)
    {
        if ($canonical === 'BUY') {
            return 'Buy';
        }

        if ($canonical === 'SELL') {
            return 'Sell';
        }

        throw new InvalidArgumentException("Invalid Bybit side type: {$canonical}");
    }

    /**
     * Returns the well formed base symbol with the quote on it.
     * E.g.: AVAXUSDT (Bybit uses same format as Binance, no separator).
     * E.g.: BNBPERP for USDC-settled contracts
     * Token and quote are stored directly on exchange_symbols.
     */
    public function baseWithQuote(#[SensitiveParameter] string $token, string $quote): string
    {
        // Bybit uses PERP suffix for USDC-settled perpetual contracts
        if ($quote === 'USDC') {
            return $token.'PERP';
        }

        return $token.$quote;
    }

    /**
     * Returns an array with an identification of the base and currency
     * quotes, as an array, as example:
     * input: BTCUSDT
     * returns: ['base' => 'BTC', 'quote' => 'USDT']
     * input: BNBPERP
     * returns: ['base' => 'BNB', 'quote' => 'USDC']
     */
    public function identifyBaseAndQuote(#[SensitiveParameter] string $token): array
    {
        // Handle PERP suffix (Bybit uses PERP for USDC-settled perpetual contracts)
        if (str_ends_with(haystack: $token, needle: 'PERP')) {
            return [
                'base' => str_replace(search: 'PERP', replace: '', subject: $token),
                'quote' => 'USDC',
            ];
        }

        $availableQuoteCurrencies = [
            'USDT', 'USDC', 'BTC', 'ETH', 'USDE',
            'DAI', 'EUR', 'GBP', 'AUD',
        ];

        foreach ($availableQuoteCurrencies as $quoteCurrency) {
            if (! (str_ends_with(haystack: $token, needle: $quoteCurrency))) {
                continue;
            }

            return [
                'base' => str_replace(search: $quoteCurrency, replace: '', subject: $token),
                'quote' => $quoteCurrency,
            ];
        }

        throw new InvalidArgumentException("Invalid token format: {$token}");
    }

    /**
     * Returns a canonical order type from Bybit order data.
     * Bybit uses separate fields: orderType and stopOrderType.
     *
     * @param  array<string, mixed>  $order
     */
    public function canonicalOrderType(array $order): string
    {
        $orderType = $order['orderType'] ?? '';
        $stopOrderType = $order['stopOrderType'] ?? '';

        if ($stopOrderType === 'StopLoss') {
            return 'STOP_MARKET';
        }

        if ($stopOrderType === 'TakeProfit') {
            return 'TAKE_PROFIT';
        }

        return match ($orderType) {
            'Market' => 'MARKET',
            'Limit' => 'LIMIT',
            default => 'UNKNOWN',
        };
    }
}
