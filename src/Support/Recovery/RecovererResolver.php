<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Recovery;

use InvalidArgumentException;
use Kraite\Core\Models\Account;

/**
 * RecovererResolver
 *
 * Maps an Account → concrete recoverer by inspecting its api_system
 * canonical name. Centralised here so the command stays exchange-
 * agnostic and adding a new exchange means one new case + one new
 * recoverer class.
 */
final class RecovererResolver
{
    public static function for(Account $account, RecoveryReport $report, ?string $tokenFilter = null): AbstractPositionRecoverer
    {
        $canonical = $account->apiSystem?->canonical;

        return match ($canonical) {
            'binance' => new BinancePositionRecoverer($account, $report, $tokenFilter),
            'bitget' => new BitgetPositionRecoverer($account, $report, $tokenFilter),
            'kucoin' => new KucoinPositionRecoverer($account, $report, $tokenFilter),
            'bybit' => new BybitPositionRecoverer($account, $report, $tokenFilter),
            default => throw new InvalidArgumentException(
                "No recoverer registered for api_system '{$canonical}' (account #{$account->id})"
            ),
        };
    }
}
