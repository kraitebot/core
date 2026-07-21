<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns\Position;

use Kraite\Core\Support\Proxies\ApiDataMapperProxy;
use Kraite\Core\Support\ValueObjects\ApiResponse;
use Kraite\Core\Trading\Exchange\Exchange;

/**
 * Backward-compatible model surface for legacy consumers.
 *
 * New production code must enter through Exchange::forPosition().
 */
trait InteractsWithApis
{
    public function apiMapper(): ApiDataMapperProxy
    {
        return Exchange::forPosition($this)->apiMapper();
    }

    public function apiUpdateMarginType(): ApiResponse
    {
        return Exchange::forPosition($this)->apiUpdateMarginType();
    }

    public function apiUpdateLeverageRatio(int $leverage): ApiResponse
    {
        return Exchange::forPosition($this)->apiUpdateLeverageRatio($leverage);
    }

    public function apiCancelOpenOrders(): ApiResponse
    {
        return Exchange::forPosition($this)->apiCancelOpenOrders();
    }

    public function apiQueryTokenTrades(): ApiResponse
    {
        return Exchange::forPosition($this)->apiQueryTokenTrades();
    }

    /**
     * @return array{type:string, side:string, position_side:string, quantity:string, position_id:int}|null
     */
    public function buildCloseOrderAttributes(): ?array
    {
        return Exchange::forPosition($this)->buildCloseOrderAttributes();
    }

    public function apiClose(): ApiResponse
    {
        return Exchange::forPosition($this)->apiClose();
    }

    public function apiCloseBitget(): ApiResponse
    {
        return Exchange::forPosition($this)->apiCloseBitget();
    }
}
