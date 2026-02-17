<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Bybit\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Account;
use Kraite\Core\Support\ValueObjects\ApiProperties;

trait MapsAccountQuery
{
    public function prepareQueryAccountProperties(Account $account): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $account);
        $properties->set('options.accountType', 'UNIFIED');

        return $properties;
    }

    public function resolveQueryAccountResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), associative: true);

        // Extract the result data from Bybit's response structure
        if (! isset($data['result'])) {
            return [];
        }

        $result = $data['result'];

        // Bybit returns account data in a 'list' array for UNIFIED accounts
        if (isset($result['list']) && is_array($result['list']) && count($result['list']) > 0) {
            $accountData = $result['list'][0];

            // Map Bybit fields to match Binance structure
            $normalized = [
                'totalWalletBalance' => $accountData['totalWalletBalance'] ?? '0',
                'totalUnrealizedProfit' => $accountData['totalPerpUPL'] ?? '0',
                'totalMaintMargin' => $accountData['totalMaintenanceMargin'] ?? '0',
                'totalMarginBalance' => $accountData['totalMarginBalance'] ?? '0',
            ];

            // Remove assets and positions arrays if present (same as Binance mapper)
            if (array_key_exists(key: 'assets', array: $normalized)) {
                unset($normalized['assets']);
            }

            if (array_key_exists(key: 'positions', array: $normalized)) {
                unset($normalized['positions']);
            }

            return $normalized;
        }

        return [];
    }
}
