<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns\Account;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Support\Proxies\ApiDataMapperProxy;
use Kraite\Core\Support\Proxies\ApiRESTProxy;
use Kraite\Core\Support\ValueObjects\ApiCredentials;
use Kraite\Core\Support\ValueObjects\ApiProperties;
use Kraite\Core\Support\ValueObjects\ApiResponse;

trait InteractsWithApis
{
    public ApiProperties $apiProperties;

    public Response $apiResponse;

    public function apiMapper()
    {
        return new ApiDataMapperProxy($this->apiSystem->canonical);
    }

    /**
     * Return the right api client object from the ApiRESTProxy given the account
     * connection id. Also, verifies if we should use test account credentials or not.
     */
    public function withApi()
    {
        // Mask values (keep last 6 chars) instead of logging raw secrets
        $masked = collect($this->all_credentials)->map(static function ($v) {
            return is_string($v) && $v !== ''
                ? str_repeat('*', max(0, mb_strlen($v) - 6)).mb_substr($v, -6)
                : $v;
        })->all();

        return new ApiRESTProxy(
            $this->apiSystem->canonical,
            new ApiCredentials($this->all_credentials)
        );
    }

    // V4 ready.
    public function apiQuery(): ApiResponse
    {
        $this->apiProperties = $this->apiMapper()->prepareQueryAccountProperties($this);
        $this->apiProperties->set('account', $this);
        $this->apiResponse = $this->withApi()->account($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveQueryAccountResponse($this->apiResponse)
        );
    }

    public function apiQueryOpenOrders(): ApiResponse
    {
        $this->apiProperties = $this->apiMapper()->prepareQueryOpenOrdersProperties($this);
        $this->apiProperties->set('account', $this);
        $this->apiResponse = $this->withApi()->getCurrentOpenOrders($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveQueryOpenOrdersResponse($this->apiResponse)
        );
    }

    /**
     * Query plan orders (stop-loss, take-profit, trigger orders).
     * Only supported by BitGet currently.
     */
    public function apiQueryPlanOrders(): ApiResponse
    {
        $this->apiProperties = $this->apiMapper()->prepareQueryPlanOrdersProperties($this);
        $this->apiProperties->set('account', $this);
        $this->apiResponse = $this->withApi()->getPlanOrders($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveQueryPlanOrdersResponse($this->apiResponse)
        );
    }

    /**
     * Query algo orders (stop-market, take-profit, trailing-stop).
     * Only supported by Binance since Dec 2025 API migration.
     */
    public function apiQueryAlgoOrders(): ApiResponse
    {
        $this->apiProperties = $this->apiMapper()->prepareQueryAlgoOrdersProperties($this);
        $this->apiProperties->set('account', $this);
        $this->apiResponse = $this->withApi()->getAlgoOpenOrders($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveQueryAlgoOrdersResponse($this->apiResponse)
        );
    }

    /**
     * Query stop orders (conditional orders).
     * Supported by Bybit (orderFilter=StopOrder) and KuCoin (separate endpoint).
     */
    public function apiQueryStopOrders(): ApiResponse
    {
        $this->apiProperties = $this->apiMapper()->prepareQueryStopOrdersProperties($this);
        $this->apiProperties->set('account', $this);
        $this->apiResponse = $this->withApi()->getStopOrders($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveQueryStopOrdersResponse($this->apiResponse)
        );
    }

    // V4 ready.
    public function apiQueryPositions(): ApiResponse
    {
        $this->apiProperties = $this->apiMapper()->prepareQueryPositionsProperties($this);
        $this->apiProperties->set('account', $this);
        $this->apiResponse = $this->withApi()->getPositions($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveQueryPositionsResponse($this->apiResponse)
        );
    }

    /**
     * Query per-symbol account configuration (leverage + marginType).
     *
     * Binance's /fapi/v3/positionRisk stopped returning leverage and marginType,
     * so a dedicated /fapi/v1/symbolConfig endpoint is used there. Other exchanges
     * expose the same information inline on their positions payload; their mappers
     * reuse the positions endpoint and normalize the shape.
     *
     * Response is keyed by symbol:
     *   [
     *     'LINKUSDT' => ['symbol' => 'LINKUSDT', 'leverage' => 20, 'marginType' => 'CROSSED'],
     *     ...
     *   ]
     */
    public function apiQuerySymbolConfig(): ApiResponse
    {
        $this->apiProperties = $this->apiMapper()->prepareQuerySymbolConfigProperties($this);
        $this->apiProperties->set('account', $this);
        $this->apiResponse = $this->withApi()->getSymbolConfig($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveQuerySymbolConfigResponse($this->apiResponse)
        );
    }

    // V4 ready.
    public function apiQueryBalance(): ApiResponse
    {
        $this->apiProperties = $this->apiMapper()->prepareGetBalanceProperties($this);
        $this->apiProperties->set('account', $this);
        $this->apiResponse = $this->withApi()->getAccountBalance($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveGetBalanceResponse($this->apiResponse, $this)
        );
    }

    /**
     * Query trade fills (historical executions).
     */
    public function apiQueryFills(): ApiResponse
    {
        $this->apiProperties = $this->apiMapper()->prepareQueryFillsProperties($this);
        $this->apiProperties->set('account', $this);
        $this->apiResponse = $this->withApi()->getFills($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveQueryFillsResponse($this->apiResponse)
        );
    }

    /**
     * Query income history from Binance (REALIZED_PNL, COMMISSION, FUNDING_FEE).
     */
    public function apiQueryIncome(string $symbol, string $incomeType, int $startTime, int $endTime): ApiResponse
    {
        $this->apiProperties = new ApiProperties;
        $this->apiProperties->set('account', $this);
        $this->apiProperties->set('options.symbol', $symbol);
        $this->apiProperties->set('options.incomeType', $incomeType);
        $this->apiProperties->set('options.startTime', $startTime);
        $this->apiProperties->set('options.endTime', $endTime);
        $this->apiProperties->set('options.limit', 1000);
        $this->apiProperties->set('options.timestamp', now()->getTimestampMs());

        $this->apiResponse = $this->withApi()->income($this->apiProperties);

        $decoded = $this->apiResponse instanceof Response
            ? json_decode((string) $this->apiResponse->getBody(), true)
            : (is_array($this->apiResponse) ? $this->apiResponse : []);

        return new ApiResponse(
            response: $this->apiResponse instanceof Response ? $this->apiResponse : null,
            result: $decoded
        );
    }

    /**
     * Query historical closed positions from Bitget with exchange-computed PnL.
     */
    public function apiQueryHistoryPositions(string $productType, ?string $symbol = null, ?int $startTime = null, ?int $endTime = null): ApiResponse
    {
        $this->apiProperties = new ApiProperties;
        $this->apiProperties->set('account', $this);
        $this->apiProperties->set('options.productType', $productType);

        if ($symbol !== null) {
            $this->apiProperties->set('options.symbol', $symbol);
        }
        if ($startTime !== null) {
            $this->apiProperties->set('options.startTime', (string) $startTime);
        }
        if ($endTime !== null) {
            $this->apiProperties->set('options.endTime', (string) $endTime);
        }
        $this->apiProperties->set('options.limit', '100');

        $this->apiResponse = $this->withApi()->historyPosition($this->apiProperties);

        $decoded = $this->apiResponse instanceof Response
            ? json_decode((string) $this->apiResponse->getBody(), true)
            : (is_array($this->apiResponse) ? $this->apiResponse : []);

        return new ApiResponse(
            response: $this->apiResponse instanceof Response ? $this->apiResponse : null,
            result: $decoded
        );
    }
}
