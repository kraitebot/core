<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns\Symbol;

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
        return Exchange::forSymbol($this)->apiMapper();
    }

    public function apiSyncCMCData(): ApiResponse
    {
        return Exchange::forSymbol($this)->apiSyncCMCData();
    }
}
