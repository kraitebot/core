<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Account;
use Kraite\Core\Support\ApiDataMappers\Bitget\BitgetProductContext;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsAccountQuery
{
    public function prepareQueryAccountProperties(Account $account): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $account);

        $context = BitgetProductContext::fromQuote($account->portfolio_quote);
        $properties->set('options.productType', $context->productType);

        return $properties;
    }

    /**
     * Resolves BitGet account response.
     *
     * BitGet V2 account structure:
     * {
     *     "code": "00000",
     *     "msg": "success",
     *     "requestTime": 1627116936176,
     *     "data": [
     *         {
     *             "marginCoin": "USDT",
     *             "locked": "0",
     *             "available": "1000.5",
     *             "crossedMaxAvailable": "1000.5",
     *             "isolatedMaxAvailable": "1000.5",
     *             "maxTransferOut": "1000.5",
     *             "accountEquity": "1000.5",
     *             "usdtEquity": "1000.5",
     *             "btcEquity": "0.025",
     *             "crossedRiskRate": "0",
     *             "crossedUnrealizedPL": "0",
     *             "crossedMarginLeverage": "1",
     *             "isolatedLongLever": "10",
     *             "isolatedShortLever": "10",
     *             "marginMode": "crossed",
     *             "posMode": "hedge_mode",
     *             "unrealizedPL": "0",
     *             "coupon": "0"
     *         }
     *     ]
     * }
     */
    public function resolveQueryAccountResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);

        $accountsData = $data['data'] ?? [];

        $accountData = collect($accountsData)->first();

        if (empty($accountData)) {
            return [];
        }

        // Map BitGet fields to match Binance structure for consistency
        return [
            'totalWalletBalance' => (string) ($accountData['accountEquity'] ?? '0'),
            'totalUnrealizedProfit' => (string) ($accountData['unrealizedPL'] ?? $accountData['crossedUnrealizedPL'] ?? '0'),
            'totalMaintMargin' => (string) ($accountData['locked'] ?? '0'),
            'totalMarginBalance' => (string) ($accountData['accountEquity'] ?? '0'),
            'availableFunds' => (string) ($accountData['available'] ?? '0'),
            'initialMargin' => (string) ($accountData['locked'] ?? '0'),
        ];
    }
}
