<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns\ApiSystem;

use Kraite\Core\Support\Proxies\ApiDataMapperProxy;
use Kraite\Core\Support\ValueObjects\ApiResponse;
use Kraite\Core\Trading\Exchange\Exchange;

/**
 * Backward-compatible model surface for legacy consumers.
 */
trait InteractsWithApis
{
    public function apiMapper(): ApiDataMapperProxy
    {
        return Exchange::forSystem($this)->apiMapper();
    }

    public function apiQueryMarketData(): ApiResponse
    {
        return Exchange::forSystem($this)->apiQueryMarketData();
    }

    public function apiQueryLeverageBracketsData(): ApiResponse
    {
        return Exchange::forSystem($this)->apiQueryLeverageBracketsData();
    }

    public function apiQueryLeverageBracketsDataForSymbol(string $symbol, ?string $quote = null): ApiResponse
    {
        return Exchange::forSystem($this)->apiQueryLeverageBracketsDataForSymbol($symbol, $quote);
    }
}
