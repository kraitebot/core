<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Account;
use Kraite\Core\Support\ApiDataMappers\Bitget\BitgetProductContext;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsAccountBalanceQuery
{
    public function prepareGetBalanceProperties(Account $account): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $account);

        $context = BitgetProductContext::fromQuote($account->portfolio_quote);
        $properties->set('options.productType', $context->productType);

        return $properties;
    }

    /**
     * Returns structured balance data for the account's portfolio quote.
     *
     * BitGet V2 response structure:
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
     *             "coupon": "0",
     *             "crossedMarginMode": "fixedMargin",
     *             "assetMode": "multiAsset"
     *         }
     *     ]
     * }
     */
    public function resolveGetBalanceResponse(Response $response, Account $account): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);
        $accountsData = $data['data'] ?? [];
        $balanceQuote = $account->balanceQuote();

        $accountData = collect($accountsData)
            ->first(static function ($acc) use ($balanceQuote) {
                return ($acc['marginCoin'] ?? '') === $balanceQuote;
            });

        if (empty($accountData)) {
            return $this->emptyAccountBalance();
        }

        // BitGet provides accountEquity (total), available, unrealizedPL
        $accountEquity = (string) ($accountData['accountEquity'] ?? '0');
        $available = (string) ($accountData['available'] ?? '0');
        $unrealizedPnl = (string) ($accountData['unrealizedPL'] ?? $accountData['crossedUnrealizedPL'] ?? '0');

        return [
            'total-wallet-balance' => $accountEquity,
            'wallet-balance' => $accountEquity,
            'available-balance' => $available,
            'cross-wallet-balance' => $accountEquity,
            'cross-unrealized-pnl' => $unrealizedPnl,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function emptyAccountBalance(): array
    {
        return [
            'total-wallet-balance' => '0',
            'wallet-balance' => '0',
            'available-balance' => '0',
            'cross-wallet-balance' => '0',
            'cross-unrealized-pnl' => '0',
        ];
    }
}
