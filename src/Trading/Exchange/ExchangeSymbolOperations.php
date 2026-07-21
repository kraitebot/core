<?php

declare(strict_types=1);

namespace Kraite\Core\Trading\Exchange;

use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Support\Proxies\ApiDataMapperProxy;

final class ExchangeSymbolOperations
{
    public function __construct(private readonly ExchangeSymbol $exchangeSymbol) {}

    public function apiMapper(string $canonical): ApiDataMapperProxy
    {
        return Exchange::forCanonical($canonical)->mapper();
    }
}
