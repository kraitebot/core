<?php

declare(strict_types=1);

namespace Kraite\Core\Trading\Exchange;

use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;

final class ExchangeManager
{
    public function forCanonical(string $canonical): CanonicalExchangeConnection
    {
        return new CanonicalExchangeConnection($canonical);
    }

    public function forAccount(Account $account): AccountExchangeOperations
    {
        return new AccountExchangeOperations($account);
    }

    public function forSystem(ApiSystem $apiSystem): ApiSystemExchangeOperations
    {
        return new ApiSystemExchangeOperations($apiSystem);
    }

    public function forExchangeSymbol(ExchangeSymbol $exchangeSymbol): ExchangeSymbolOperations
    {
        return new ExchangeSymbolOperations($exchangeSymbol);
    }

    public function forOrder(Order $order): OrderExchangeOperations
    {
        return new OrderExchangeOperations($order);
    }

    public function forPosition(Position $position): PositionExchangeOperations
    {
        return new PositionExchangeOperations($position);
    }

    public function forSymbol(Symbol $symbol): SymbolExchangeOperations
    {
        return new SymbolExchangeOperations($symbol);
    }
}
