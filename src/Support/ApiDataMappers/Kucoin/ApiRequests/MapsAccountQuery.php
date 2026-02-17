<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Kucoin\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Account;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsAccountQuery
{
    public function prepareQueryAccountProperties(Account $account): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $account);

        return $properties;
    }

    /**
     * Resolves KuCoin account response.
     *
     * KuCoin Futures account overview structure:
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
    public function resolveQueryAccountResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);

        $accountData = $data['data'] ?? [];

        if (empty($accountData)) {
            return [];
        }

        // Map KuCoin fields to match Binance structure for consistency
        return [
            'totalWalletBalance' => (string) ($accountData['marginBalance'] ?? '0'),
            'totalUnrealizedProfit' => (string) ($accountData['unrealisedPNL'] ?? '0'),
            'totalMaintMargin' => (string) ($accountData['positionMargin'] ?? '0'),
            'totalMarginBalance' => (string) ($accountData['accountEquity'] ?? '0'),
            'availableFunds' => (string) ($accountData['availableBalance'] ?? '0'),
            'initialMargin' => (string) ($accountData['orderMargin'] ?? '0'),
        ];
    }
}
