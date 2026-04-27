<?php

declare(strict_types=1);

namespace Kraite\Core\Support;

/**
 * Resolves TP / SL percentages by reconciling per-symbol overrides
 * against per-account defaults.
 *
 * Symbols can pin their own profit_percentage / stop_market_percentage
 * once backtesting validates a better value for that token. Accounts
 * still keep a global default, plus a kill-switch (override_tp /
 * override_sl) that forces the account default to win when set.
 *
 * Resolution rule (independent for TP and SL):
 *
 *   symbol value is NULL       → account value wins (override flag IGNORED)
 *   account override === true  → account value wins
 *   otherwise                  → symbol value wins
 *
 * The symbol-NULL fallback is unconditional: an account with override=true
 * and a symbol with no override still resolves to the account value, never
 * to "null".
 *
 * String-in / string-out contract — no float casting, since these values
 * feed Math helpers that rely on exact decimal precision downstream.
 */
final class TpSlResolver
{
    public static function resolve(
        ?string $symbolValue,
        bool $accountOverride,
        string $accountValue,
    ): string {
        if ($symbolValue === null || $symbolValue === '') {
            return $accountValue;
        }

        if ($accountOverride) {
            return $accountValue;
        }

        return $symbolValue;
    }
}
