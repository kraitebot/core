<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Account;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsAccountBalanceQuery
{
    public function prepareGetBalanceProperties(Account $account): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $account);

        // BitGet V2 requires productType for futures
        $properties->set('options.productType', 'USDT-FUTURES');

        return $properties;
    }

    /**
     * Returns structured balance data for the account's trading quote.
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

        // Find USDT account data
        $accountData = collect($accountsData)
            ->first(static function ($acc) {
                return ($acc['marginCoin'] ?? '') === 'USDT';
            });

        if (empty($accountData)) {
            return [
                'wallet-balance' => '0',
                'available-balance' => '0',
                'cross-wallet-balance' => '0',
                'cross-unrealized-pnl' => '0',
            ];
        }

        // BitGet provides accountEquity (total), available, unrealizedPL
        $accountEquity = (string) ($accountData['accountEquity'] ?? '0');
        $available = (string) ($accountData['available'] ?? '0');
        $unrealizedPnl = (string) ($accountData['unrealizedPL'] ?? $accountData['crossedUnrealizedPL'] ?? '0');

        return [
            'wallet-balance' => $accountEquity,
            'available-balance' => $available,
            'cross-wallet-balance' => $accountEquity,
            'cross-unrealized-pnl' => $unrealizedPnl,
        ];
    }
}
