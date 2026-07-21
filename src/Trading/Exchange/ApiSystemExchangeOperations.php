<?php

declare(strict_types=1);

namespace Kraite\Core\Trading\Exchange;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Support\ApiDataMappers\Bitget\BitgetProductContext;
use Kraite\Core\Support\Proxies\ApiDataMapperProxy;
use Kraite\Core\Support\ValueObjects\ApiProperties;
use Kraite\Core\Support\ValueObjects\ApiResponse;

final class ApiSystemExchangeOperations
{
    private ApiProperties $apiProperties;

    private Response $apiResponse;

    public function __construct(private readonly ApiSystem $apiSystem) {}

    public function apiMapper(): ApiDataMapperProxy
    {
        return Exchange::forCanonical($this->apiSystem->canonical)->mapper();
    }

    // V4 ready.
    public function apiQueryMarketData(): ApiResponse
    {
        $account = Account::admin($this->apiSystem->canonical);

        if ($this->apiSystem->canonical === 'bitget') {
            return $this->apiQueryBitgetMarketData($account);
        }

        $this->apiProperties = $this->apiMapper()->prepareQueryMarketDataProperties($this->apiSystem);
        $this->apiProperties->set('account', $account);
        $this->apiResponse = $account->withApi()->getExchangeInformation($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveQueryMarketDataResponse($this->apiResponse)
        );
    }

    // V4 ready.
    public function apiQueryLeverageBracketsData(): ApiResponse
    {
        $account = Account::admin($this->apiSystem->canonical);

        $this->apiProperties = $this->apiMapper()->prepareQueryLeverageBracketsDataProperties($this->apiSystem);
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
        $account = Account::admin($this->apiSystem->canonical);

        $this->apiProperties = $this->apiSystem->canonical === 'bitget'
            ? $this->apiMapper()->prepareQueryLeverageBracketsDataProperties($this->apiSystem, $symbol, $quote)
            : $this->apiMapper()->prepareQueryLeverageBracketsDataProperties($this->apiSystem, $symbol);
        $this->apiProperties->set('account', $account);
        $this->apiResponse = $account->withApi()->getLeverageBrackets($this->apiProperties);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveLeverageBracketsDataResponse($this->apiResponse)
        );
    }

    private function apiQueryBitgetMarketData(Account $account): ApiResponse
    {
        $responses = [];

        foreach (BitgetProductContext::supportedQuotes() as $quote) {
            $this->apiProperties = $this->apiMapper()->prepareQueryMarketDataProperties($this->apiSystem, $quote);
            $this->apiProperties->set('account', $account);
            $responses[] = $account->withApi()->getExchangeInformation($this->apiProperties);
        }

        $this->apiResponse = $this->apiMapper()->mergeQueryMarketDataResponses($responses);

        return new ApiResponse(
            response: $this->apiResponse,
            result: $this->apiMapper()->resolveQueryMarketDataResponse($this->apiResponse)
        );
    }
}
