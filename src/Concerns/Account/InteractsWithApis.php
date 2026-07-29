<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns\Account;

use Kraite\Core\Enums\BitgetAccountMode;
use Kraite\Core\Support\Proxies\ApiDataMapperProxy;
use Kraite\Core\Support\Proxies\ApiRESTProxy;
use Kraite\Core\Support\ValueObjects\ApiResponse;
use Kraite\Core\Trading\Exchange\Exchange;

/**
 * Backward-compatible model surface for legacy consumers.
 *
 * New production code must enter through Exchange::forAccount().
 */
trait InteractsWithApis
{
    public function apiMapper(): ApiDataMapperProxy
    {
        return Exchange::forAccount($this)->apiMapper();
    }

    public function resolveBitgetAccountMode(): BitgetAccountMode
    {
        return Exchange::forAccount($this)->resolveBitgetAccountMode();
    }

    public function withApi(): ApiRESTProxy
    {
        return Exchange::forAccount($this)->withApi();
    }

    public function apiQuery(): ApiResponse
    {
        return Exchange::forAccount($this)->apiQuery();
    }

    public function apiQueryOpenOrders(): ApiResponse
    {
        return Exchange::forAccount($this)->apiQueryOpenOrders();
    }

    public function apiQueryPlanOrders(): ApiResponse
    {
        return Exchange::forAccount($this)->apiQueryPlanOrders();
    }

    public function apiQueryAlgoOrders(): ApiResponse
    {
        return Exchange::forAccount($this)->apiQueryAlgoOrders();
    }

    public function apiQueryStopOrders(): ApiResponse
    {
        return Exchange::forAccount($this)->apiQueryStopOrders();
    }

    public function apiQueryPositions(): ApiResponse
    {
        return Exchange::forAccount($this)->apiQueryPositions();
    }

    public function apiQuerySymbolConfig(): ApiResponse
    {
        return Exchange::forAccount($this)->apiQuerySymbolConfig();
    }

    public function apiQueryBalance(): ApiResponse
    {
        return Exchange::forAccount($this)->apiQueryBalance();
    }

    public function apiQueryWithdrawalPermission(): ApiResponse
    {
        return Exchange::forAccount($this)->apiQueryWithdrawalPermission();
    }

    public function apiQueryFills(): ApiResponse
    {
        return Exchange::forAccount($this)->apiQueryFills();
    }

    public function apiQueryIncome(string $symbol, string $incomeType, int $startTime, int $endTime): ApiResponse
    {
        return Exchange::forAccount($this)->apiQueryIncome($symbol, $incomeType, $startTime, $endTime);
    }

    public function apiQueryAccountIncome(int $startTime, int $endTime, int $limit = 1000): ApiResponse
    {
        return Exchange::forAccount($this)->apiQueryAccountIncome($startTime, $endTime, $limit);
    }

    public function apiQueryHistoryPositions(
        string $productType,
        ?string $symbol = null,
        ?int $startTime = null,
        ?int $endTime = null,
        ?string $cursor = null,
    ): ApiResponse {
        return Exchange::forAccount($this)->apiQueryHistoryPositions(
            $productType,
            $symbol,
            $startTime,
            $endTime,
            $cursor,
        );
    }
}
