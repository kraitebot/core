<?php

declare(strict_types=1);

namespace Kraite\Core\Trading\Exchange;

use Illuminate\Support\Facades\Facade;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;

/**
 * @method static AccountExchangeOperations forAccount(Account $account)
 * @method static ApiSystemExchangeOperations forSystem(ApiSystem $apiSystem)
 * @method static ExchangeSymbolOperations forExchangeSymbol(ExchangeSymbol $exchangeSymbol)
 * @method static OrderExchangeOperations forOrder(Order $order)
 * @method static PositionExchangeOperations forPosition(Position $position)
 * @method static SymbolExchangeOperations forSymbol(Symbol $symbol)
 * @method static CanonicalExchangeConnection forCanonical(string $canonical)
 *
 * @see ExchangeManager
 */
final class Exchange extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ExchangeManager::class;
    }
}
