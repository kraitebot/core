<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns\Account;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Enums\BitgetAccountMode;
use Kraite\Core\Support\Connectivity\BitgetAccountModeDetector;
use Kraite\Core\Support\Proxies\ApiDataMapperProxy;
use Kraite\Core\Support\Proxies\ApiRESTProxy;
use Kraite\Core\Support\Security\ExchangeApiKeyPermissions;
use Kraite\Core\Support\ValueObjects\ApiCredentials;
use Kraite\Core\Support\ValueObjects\ApiProperties;
use Kraite\Core\Support\ValueObjects\ApiResponse;
use UnexpectedValueException;

trait InteractsWithApis
{
    public ApiProperties $apiProperties;

    public Response $apiResponse;

    public function apiMapper()
    {
        return new ApiDataMapperProxy($this->apiSystem->canonical);
    }

    /**
     * The BitGet account mode (classic vs unified) driving which API
     * generation private calls use. Detected once via a cheap probe and
     * persisted; non-persisted accounts (Account::admin/temporary) detect
     * per instance and memoise on the attribute.
     */
    public function resolveBitgetAccountMode(): BitgetAccountMode
    {
        $stored = $this->bitget_account_mode;

        if (is_string($stored) && $stored !== '') {
            return BitgetAccountMode::from($stored);
        }

        $mode = app(BitgetAccountModeDetector::class)->detect($this);

        $this->bitget_account_mode = $mode->value;

        if ($this->exists) {
            // Quiet save: mode discovery is plumbing, not a product event —
            // it must not trigger observer side effects mid-API-call.
            $this->saveQuietly();
        }

        return $mode;
    }

    /**
     * Return the right api client object from the ApiRESTProxy given the account
     * connection id. Also, verifies if we should use test account credentials or not.
     */
    public function withApi(): ApiRESTProxy
    {
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

    public function apiQueryWithdrawalPermission(): ApiResponse
    {
        $exchange = $this->apiSystem->canonical;
        $this->apiProperties = new ApiProperties;
        $this->apiProperties->set('account', $this);
        $this->apiResponse = $this->withApi()->getApiKeyPermissions($this->apiProperties);
        $payload = json_decode((string) $this->apiResponse->getBody(), associative: true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($payload)) {
            throw new UnexpectedValueException("{$exchange} did not return valid API key permissions.");
        }

        return new ApiResponse(
            response: $this->apiResponse,
            result: [
                'withdrawals_enabled' => ExchangeApiKeyPermissions::withdrawalsEnabled($exchange, $payload),
            ],
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
    public function apiQueryHistoryPositions(
        string $productType,
        ?string $symbol = null,
        ?int $startTime = null,
        ?int $endTime = null,
        ?string $cursor = null,
    ): ApiResponse {
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
        if ($cursor !== null && $cursor !== '') {
            $this->apiProperties->set('options.cursor', $cursor);
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
