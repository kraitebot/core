<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Bitget\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\ApiDataMappers\Bitget\BitgetProductContext;
use Kraite\Core\Support\ValueObjects\ApiProperties;
use UnexpectedValueException;

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

        if (is_array($accountsData) && ! array_is_list($accountsData)) {
            $accountEquity = (string) ($accountsData['accountEquity'] ?? '0');

            return [
                'totalWalletBalance' => $accountEquity,
                'totalUnrealizedProfit' => (string) ($accountsData['unrealisedPnl'] ?? '0'),
                'totalMaintMargin' => (string) ($accountsData['mmr'] ?? '0'),
                'totalMarginBalance' => $accountEquity,
                'availableFunds' => (string) ($accountsData['effEquity'] ?? '0'),
                'initialMargin' => (string) ($accountsData['imr'] ?? '0'),
            ];
        }

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

    public function prepareQueryPositionModeProperties(Position $position): ApiProperties
    {
        $properties = new ApiProperties;
        $properties->set('relatable', $position->account);
        $context = BitgetProductContext::fromQuote($position->exchangeSymbol->quote);
        $properties->set('options.productType', $context->productType);
        $properties->set('options.marginCoin', $context->marginCoin);
        $properties->set('options.symbol', (string) $position->exchangeSymbol->asset);

        return $properties;
    }

    public function resolveQueryPositionModeResponse(Response $response, Position $position): string
    {
        $data = json_decode((string) $response->getBody(), associative: true);
        $account = is_array($data['data'] ?? null) ? $data['data'] : [];

        $unifiedPositionMode = $account['holdMode'] ?? null;
        if (is_string($unifiedPositionMode)
            && in_array($unifiedPositionMode, ['hedge_mode', 'one_way_mode'], strict: true)) {
            return $unifiedPositionMode;
        }

        $quote = mb_strtoupper((string) $position->exchangeSymbol->quote);
        $hasExpectedMarginCoin = mb_strtoupper((string) ($account['marginCoin'] ?? '')) === $quote;
        $positionMode = $hasExpectedMarginCoin ? ($account['posMode'] ?? null) : null;

        if (! is_string($positionMode)
            || ! in_array($positionMode, ['hedge_mode', 'one_way_mode'], strict: true)) {
            throw new UnexpectedValueException(
                "Bitget did not return a valid {$quote} position mode; opening cannot safely continue."
            );
        }

        return $positionMode;
    }
}
