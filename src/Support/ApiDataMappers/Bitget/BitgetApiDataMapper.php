<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Bitget;

use InvalidArgumentException;
use Kraite\Core\Abstracts\BaseDataMapper;
use Kraite\Core\Support\Math;
use Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests\MapsAccountBalanceQuery;
use Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests\MapsAccountQuery;
use Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests\MapsAccountQueryTrades;
use Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests\MapsCancelOrders;
use Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests\MapsExchangeInformationQuery;
use Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests\MapsKlinesQuery;
use Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests\MapsLeverageBracketsQuery;
use Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests\MapsMarkPriceQuery;
use Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests\MapsModifyTpsl;
use Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests\MapsOpenOrdersQuery;
use Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests\MapsOrderCancel;
use Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests\MapsOrderModify;
use Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests\MapsOrderQuery;
use Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests\MapsPlaceOrder;
use Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests\MapsPlacePlanOrder;
use Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests\MapsPlacePosTpsl;
use Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests\MapsPlaceTpslOrder;
use Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests\MapsPlanOrdersQuery;
use Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests\MapsPositionsQuery;
use Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests\MapsServerTimeQuery;
use Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests\MapsSymbolConfigQuery;
use Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests\MapsSymbolMarginType;
use Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests\MapsTokenLeverageRatios;
use SensitiveParameter;

final class BitgetApiDataMapper extends BaseDataMapper
{
    use MapsAccountBalanceQuery;
    use MapsAccountQuery;
    use MapsAccountQueryTrades;
    use MapsCancelOrders;
    use MapsExchangeInformationQuery;
    use MapsKlinesQuery;
    use MapsLeverageBracketsQuery;
    use MapsMarkPriceQuery;
    use MapsModifyTpsl;
    use MapsOpenOrdersQuery;
    use MapsOrderCancel;
    use MapsOrderModify;
    use MapsOrderQuery;
    use MapsPlaceOrder;
    use MapsPlacePlanOrder;
    use MapsPlacePosTpsl;
    use MapsPlaceTpslOrder;
    use MapsPlanOrdersQuery;
    use MapsPositionsQuery;
    use MapsServerTimeQuery;
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
        if ($canonical === 'LONG' || $canonical === 'long') {
            return 'long';
        }

        if ($canonical === 'SHORT' || $canonical === 'short') {
            return 'short';
        }

        throw new InvalidArgumentException("Invalid BitGet direction type: {$canonical}");
    }

    public function sideType(string $canonical)
    {
        if ($canonical === 'BUY' || $canonical === 'buy') {
            return 'buy';
        }

        if ($canonical === 'SELL' || $canonical === 'sell') {
            return 'sell';
        }

        throw new InvalidArgumentException("Invalid BitGet side type: {$canonical}");
    }

    /**
     * Returns the well formed base symbol with the quote on it.
     *
     * BitGet V2 API uses simple format like BTCUSDT, ETHUSDT.
     * No suffix needed for perpetual contracts.
     *
     * Token and quote are stored directly on exchange_symbols.
     */
    public function baseWithQuote(#[SensitiveParameter] string $token, string $quote): string
    {
        // BitGet uses simple format: BTCUSDT, ETHUSDT (similar to Binance)
        return $token.$quote;
    }

    /**
     * Returns an array with an identification of the base and currency
     * quotes, as an array, as example:
     *
     * input: BTCUSDT
     * returns: ['base' => 'BTC', 'quote' => 'USDT']
     *
     * input: ETHUSDC
     * returns: ['base' => 'ETH', 'quote' => 'USDC']
     */
    public function identifyBaseAndQuote(string $symbol): array
    {
        // BitGet primarily uses USDT, USDC as quote currencies
        $availableQuoteCurrencies = [
            'USDT', 'USDC', 'USD',
        ];

        foreach ($availableQuoteCurrencies as $quoteCurrency) {
            if (! (str_ends_with(haystack: $symbol, needle: $quoteCurrency))) {
                continue;
            }

            $base = str_replace(search: $quoteCurrency, replace: '', subject: $symbol);

            return [
                'base' => $base,
                'quote' => $quoteCurrency,
            ];
        }

        throw new InvalidArgumentException("Invalid BitGet symbol format: {$symbol}");
    }

    /**
     * Returns a canonical order type from BitGet order data.
     * Plan orders use planType field, regular orders use orderType.
     *
     * @param  array<string, mixed>  $order
     */
    public function canonicalOrderType(array $order): string
    {
        // Plan orders (from MapsPlanOrdersQuery)
        $planType = $order['planType'] ?? '';
        if ($planType !== '') {
            return match ($planType) {
                'pos_loss', 'loss_plan' => 'STOP_MARKET',
                'pos_profit', 'profit_plan' => 'TAKE_PROFIT',
                'normal_plan' => 'STOP_MARKET',
                'track_plan' => 'STOP_MARKET',
                default => 'UNKNOWN',
            };
        }

        // Regular orders
        $orderType = mb_strtolower($order['orderType'] ?? '');
        $triggerPrice = (string) ($order['triggerPrice'] ?? '0');

        if (Math::gt($triggerPrice, '0')) {
            return 'STOP_MARKET';
        }

        return match ($orderType) {
            'market' => 'MARKET',
            'limit' => 'LIMIT',
            default => 'UNKNOWN',
        };
    }
}
