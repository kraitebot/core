<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Binance;

use InvalidArgumentException;
use Kraite\Core\Abstracts\BaseDataMapper;
use Kraite\Core\Support\ApiDataMappers\Binance\ApiRequests\MapsAccountBalanceQuery;
use Kraite\Core\Support\ApiDataMappers\Binance\ApiRequests\MapsAccountQuery;
use Kraite\Core\Support\ApiDataMappers\Binance\ApiRequests\MapsAccountQueryTrades;
use Kraite\Core\Support\ApiDataMappers\Binance\ApiRequests\MapsAlgoOrdersQuery;
use Kraite\Core\Support\ApiDataMappers\Binance\ApiRequests\MapsCancelOrders;
use Kraite\Core\Support\ApiDataMappers\Binance\ApiRequests\MapsExchangeInformationQuery;
use Kraite\Core\Support\ApiDataMappers\Binance\ApiRequests\MapsKlinesQuery;
use Kraite\Core\Support\ApiDataMappers\Binance\ApiRequests\MapsLeverageBracketsQuery;
use Kraite\Core\Support\ApiDataMappers\Binance\ApiRequests\MapsMarkPriceQuery;
use Kraite\Core\Support\ApiDataMappers\Binance\ApiRequests\MapsOpenOrdersQuery;
use Kraite\Core\Support\ApiDataMappers\Binance\ApiRequests\MapsOrderCancel;
use Kraite\Core\Support\ApiDataMappers\Binance\ApiRequests\MapsOrderModify;
use Kraite\Core\Support\ApiDataMappers\Binance\ApiRequests\MapsOrderQuery;
use Kraite\Core\Support\ApiDataMappers\Binance\ApiRequests\MapsPlaceAlgoOrder;
use Kraite\Core\Support\ApiDataMappers\Binance\ApiRequests\MapsPlaceOrder;
use Kraite\Core\Support\ApiDataMappers\Binance\ApiRequests\MapsPositionsQuery;
use Kraite\Core\Support\ApiDataMappers\Binance\ApiRequests\MapsServerTimeQuery;
use Kraite\Core\Support\ApiDataMappers\Binance\ApiRequests\MapsSymbolMarginType;
use Kraite\Core\Support\ApiDataMappers\Binance\ApiRequests\MapsTokenLeverageRatios;

final class BinanceApiDataMapper extends BaseDataMapper
{
    use MapsAccountBalanceQuery;
    use MapsAccountQuery;
    use MapsAccountQueryTrades;
    use MapsAlgoOrdersQuery;
    use MapsCancelOrders;
    use MapsExchangeInformationQuery;
    use MapsKlinesQuery;
    use MapsLeverageBracketsQuery;
    use MapsMarkPriceQuery;
    use MapsOpenOrdersQuery;
    use MapsOrderCancel;
    use MapsOrderModify;
    use MapsOrderQuery;
    use MapsPlaceAlgoOrder;
    use MapsPlaceOrder;
    use MapsPositionsQuery;
    use MapsServerTimeQuery;
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

        throw new InvalidArgumentException("Invalid Binance direction type: {$canonical}");
    }

    public function sideType(string $canonical)
    {
        if ($canonical === 'BUY') {
            return 'BUY';
        }

        if ($canonical === 'SELL') {
            return 'SELL';
        }

        throw new InvalidArgumentException("Invalid Binance side type: {$canonical}");
    }

    /**
     * Returns the well formed base symbol with the quote on it.
     * E.g.: AVAXUSDT. Token and quote are stored directly on exchange_symbols.
     */
    public function baseWithQuote(#[\SensitiveParameter] string $token, string $quote): string
    {
        return $token.$quote;
    }

    /**
     * Returns an array with an identification of the base and currency
     * quotes, as an array, as example:
     * input: MANAUSDT
     * returns: ['MANA', 'USDT']
     */
    public function identifyBaseAndQuote(#[\SensitiveParameter] string $token): array
    {
        $availableQuoteCurrencies = [
            'USDT', 'BUSD', 'USDC', 'BTC', 'ETH', 'BNB',
            'AUD', 'EUR', 'GBP', 'TRY', 'RUB', 'BRL',
        ];

        foreach ($availableQuoteCurrencies as $quoteCurrency) {
            if (!(str_ends_with(haystack: $token, needle: $quoteCurrency))) { continue; }

return [
                    'base' => str_replace(search: $quoteCurrency, replace: '', subject: $token),
                    'quote' => $quoteCurrency,
                ];
        }

        throw new InvalidArgumentException("Invalid token format: {$token}");
    }

    /**
     * Returns a canonical order type from Binance order data.
     *
     * @param  array<string, mixed>  $order
     */
    public function canonicalOrderType(array $order): string
    {
        $type = $order['type'] ?? '';

        return match ($type) {
            'MARKET' => 'MARKET',
            'LIMIT' => 'LIMIT',
            'STOP', 'STOP_MARKET', 'STOP_LIMIT' => 'STOP_MARKET',
            'TAKE_PROFIT', 'TAKE_PROFIT_MARKET', 'TAKE_PROFIT_LIMIT' => 'TAKE_PROFIT',
            'TRAILING_STOP_MARKET' => 'STOP_MARKET',
            default => 'UNKNOWN',
        };
    }
}
