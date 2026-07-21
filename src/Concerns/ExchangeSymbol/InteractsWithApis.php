<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns\ExchangeSymbol;

use Kraite\Core\Support\Proxies\ApiDataMapperProxy;
use Kraite\Core\Trading\Exchange\Exchange;

/**
 * Backward-compatible model surface for legacy consumers.
 */
trait InteractsWithApis
{
    public function apiMapper(string $canonical): ApiDataMapperProxy
    {
        return Exchange::forExchangeSymbol($this)->apiMapper($canonical);
    }
}
