<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns\Order;

use Kraite\Core\Models\Account;
use Kraite\Core\Support\Proxies\ApiDataMapperProxy;
use Kraite\Core\Support\ValueObjects\ApiResponse;
use Kraite\Core\Trading\Exchange\Exchange;

/**
 * Backward-compatible model surface for legacy consumers.
 *
 * New production code must enter through Exchange::forOrder().
 */
trait InteractsWithApis
{
    public function apiAccount(): Account
    {
        return Exchange::forOrder($this)->apiAccount();
    }

    public function apiMapper(): ApiDataMapperProxy
    {
        return Exchange::forOrder($this)->apiMapper();
    }

    public function apiCancel(): ApiResponse
    {
        return Exchange::forOrder($this)->apiCancel();
    }

    public function apiCancelDefault(): ApiResponse
    {
        return Exchange::forOrder($this)->apiCancelDefault();
    }

    public function apiCancelAlgo(): ApiResponse
    {
        return Exchange::forOrder($this)->apiCancelAlgo();
    }

    public function apiModify(string|int|float|null $quantity = null, string|int|float|null $price = null): ApiResponse
    {
        return Exchange::forOrder($this)->apiModify($quantity, $price);
    }

    public function apiQuery(): ApiResponse
    {
        return Exchange::forOrder($this)->apiQuery();
    }

    public function apiQueryDefault(): ApiResponse
    {
        return Exchange::forOrder($this)->apiQueryDefault();
    }

    public function apiSync(): ApiResponse
    {
        return Exchange::forOrder($this)->apiSync();
    }

    public function apiSyncDefault(): ApiResponse
    {
        return Exchange::forOrder($this)->apiSyncDefault();
    }

    public function apiPlace(): ApiResponse
    {
        return Exchange::forOrder($this)->apiPlace();
    }

    public function apiPlaceDefault(): ApiResponse
    {
        return Exchange::forOrder($this)->apiPlaceDefault();
    }

    public function apiPlaceAlgo(): ApiResponse
    {
        return Exchange::forOrder($this)->apiPlaceAlgo();
    }

    public function apiQueryAlgo(): ApiResponse
    {
        return Exchange::forOrder($this)->apiQueryAlgo();
    }

    public function apiSyncAlgo(): ApiResponse
    {
        return Exchange::forOrder($this)->apiSyncAlgo();
    }

    public function apiQueryStopOrder(): ApiResponse
    {
        return Exchange::forOrder($this)->apiQueryStopOrder();
    }

    public function apiSyncStopOrder(): ApiResponse
    {
        return Exchange::forOrder($this)->apiSyncStopOrder();
    }

    public function apiCancelStopOrder(): ApiResponse
    {
        return Exchange::forOrder($this)->apiCancelStopOrder();
    }

    public function apiPlacePlanOrder(): ApiResponse
    {
        return Exchange::forOrder($this)->apiPlacePlanOrder();
    }

    public function apiPlaceTpslOrder(): ApiResponse
    {
        return Exchange::forOrder($this)->apiPlaceTpslOrder();
    }

    public function apiQueryPlanOrder(): ApiResponse
    {
        return Exchange::forOrder($this)->apiQueryPlanOrder();
    }

    public function apiSyncPlanOrder(): ApiResponse
    {
        return Exchange::forOrder($this)->apiSyncPlanOrder();
    }

    public function apiQueryPlanOrderHistory(): ApiResponse
    {
        return Exchange::forOrder($this)->apiQueryPlanOrderHistory();
    }

    public function apiCancelPlanOrder(): ApiResponse
    {
        return Exchange::forOrder($this)->apiCancelPlanOrder();
    }

    public function apiModifyTpsl(string $newTriggerPrice, ?string $quantity = null): ApiResponse
    {
        return Exchange::forOrder($this)->apiModifyTpsl($newTriggerPrice, $quantity);
    }

    protected function resolveSyncedPrice(mixed $incoming): mixed
    {
        return Exchange::forOrder($this)->resolveSyncedPrice($incoming);
    }

    protected function resolveSyncedQuantity(mixed $incoming): mixed
    {
        return Exchange::forOrder($this)->resolveSyncedQuantity($incoming);
    }
}
