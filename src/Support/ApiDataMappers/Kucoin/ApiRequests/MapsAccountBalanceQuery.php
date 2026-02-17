<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Kucoin\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Account;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsAccountBalanceQuery
{
    public function prepareGetBalanceProperties(Account $account): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $account);

        // KuCoin requires currency parameter
        $tradingQuote = $account->tradingQuote->canonical ?? 'USDT';
        $properties->set('options.currency', $tradingQuote);

        return $properties;
    }

    /**
     * Returns structured balance data for the account's trading quote.
     *
     * KuCoin Futures response structure:
     * {
     *     "code": "200000",
     *     "data": {
     *         "accountEquity": 99.8999305281,
     *         "unrealisedPNL": 0,
     *         "marginBalance": 99.8999305281,
     *         "positionMargin": 0,
     *         "orderMargin": 0,
     *         "frozenFunds": 0,
     *         "availableBalance": 99.8999305281,
     *         "currency": "USDT"
     *     }
     * }
     */
    public function resolveGetBalanceResponse(Response $response, Account $account): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);
        $accountData = $data['data'] ?? [];

        if (empty($accountData)) {
            return [
                'wallet-balance' => '0',
                'available-balance' => '0',
                'cross-wallet-balance' => '0',
                'cross-unrealized-pnl' => '0',
            ];
        }

        // KuCoin provides accountEquity (total), marginBalance, availableBalance
        $accountEquity = (string) ($accountData['accountEquity'] ?? '0');
        $marginBalance = (string) ($accountData['marginBalance'] ?? '0');
        $availableBalance = (string) ($accountData['availableBalance'] ?? '0');
        $unrealisedPnl = (string) ($accountData['unrealisedPNL'] ?? '0');

        return [
            'wallet-balance' => $marginBalance,
            'available-balance' => $availableBalance,
            'cross-wallet-balance' => $accountEquity,
            'cross-unrealized-pnl' => $unrealisedPnl,
        ];
    }
}
