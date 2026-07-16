<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns\ApiSystem;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Account;
use Kraite\Core\Support\ApiDataMappers\Bitget\BitgetProductContext;
use Kraite\Core\Support\Proxies\ApiDataMapperProxy;
use Kraite\Core\Support\ValueObjects\ApiProperties;
use Kraite\Core\Support\ValueObjects\ApiResponse;

trait InteractsWithApis
{
    public ApiProperties $apiProperties;

    public Response $apiResponse;

    public function apiMapper()
    {
        return new ApiDataMapperProxy($this->canonical);
    }

    // V4 ready.
    public function apiQueryMarketData(): ApiResponse
    {
        $account = Account::admin($this->canonical);

        if ($this->canonical === 'bitget') {
            return $this->apiQueryBitgetMarketData($account);
        }

        $this->apiProperties = $this->apiMapper()->prepareQueryMarketDataProperties($this);
        $this->apiProperties->set('account', $account);
        $this->apiResponse = $account->withApi()->getExchangeInformation($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveQueryMarketDataResponse($this->apiResponse)
        );
    }

    private function apiQueryBitgetMarketData(Account $account): ApiResponse
    {
        $responses = [];

        foreach (BitgetProductContext::supportedQuotes() as $quote) {
            $this->apiProperties = $this->apiMapper()->prepareQueryMarketDataProperties($this, $quote);
            $this->apiProperties->set('account', $account);
            $responses[] = $account->withApi()->getExchangeInformation($this->apiProperties);
        }

        $this->apiResponse = $this->apiMapper()->mergeQueryMarketDataResponses($responses);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveQueryMarketDataResponse($this->apiResponse)
        );
    }

    // V4 ready.
    public function apiQueryLeverageBracketsData(): ApiResponse
    {
        $account = Account::admin($this->canonical);

        $this->apiProperties = $this->apiMapper()->prepareQueryLeverageBracketsDataProperties($this);
        $this->apiProperties->set('account', $account);
        $this->apiResponse = $account->withApi()->getLeverageBrackets($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveLeverageBracketsDataResponse($this->apiResponse)
        );
    }

    /**
     * Query leverage brackets for a specific symbol.
     *
     * Used by exchanges that require per-symbol API calls (Bybit, KuCoin).
     */
    public function apiQueryLeverageBracketsDataForSymbol(string $symbol, ?string $quote = null): ApiResponse
    {
        $account = Account::admin($this->canonical);

        $this->apiProperties = $this->canonical === 'bitget'
            ? $this->apiMapper()->prepareQueryLeverageBracketsDataProperties($this, $symbol, $quote)
            : $this->apiMapper()->prepareQueryLeverageBracketsDataProperties($this, $symbol);
        $this->apiProperties->set('account', $account);
        $this->apiResponse = $account->withApi()->getLeverageBrackets($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveLeverageBracketsDataResponse($this->apiResponse)
        );
    }
}
