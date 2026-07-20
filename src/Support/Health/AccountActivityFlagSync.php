<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Health;

use Illuminate\Support\Facades\Log;
use Kraite\Core\Models\Account;

/**
 * Keeps the allow_other_positions / allow_other_orders protection flags
 * aligned with live evidence of user activity on the exchange account.
 *
 * - Foreign activity present → both flags ON. Protection may always
 *   tighten, even on evidence gathered mid-position-open.
 * - No foreign activity → both flags OFF (restores total-balance sizing
 *   and Kraite-exclusive cleanup), but ONLY when $canDisable says the
 *   evidence is reliable — never on an in-flight tick, where local
 *   order rows lag the exchange.
 *
 * A standing user LIMIT ORDER alone enables BOTH flags: once it fills
 * it becomes a position the bot must never touch, so the positions
 * scope is protected pre-emptively and sizing moves to available
 * balance immediately.
 */
final class AccountActivityFlagSync
{
    public static function sync(Account $account, bool $hasForeign, bool $canDisable): ?string
    {
        $current = $account->allow_other_positions && $account->allow_other_orders;
        $mixed = $account->allow_other_positions !== $account->allow_other_orders;

        if ($hasForeign && (! $current || $mixed)) {
            return self::apply($account, true, 'enabled');
        }

        if (! $hasForeign && ($account->allow_other_positions || $account->allow_other_orders)) {
            if (! $canDisable) {
                return null;
            }

            return self::apply($account, false, 'disabled');
        }

        return null;
    }

    private static function apply(Account $account, bool $value, string $change): string
    {
        $account->forceFill([
            'allow_other_positions' => $value,
            'allow_other_orders' => $value,
        ])->save();

        Log::channel('jobs')->info('[ACTIVITY-FLAGS] protection flags '.$change, [
            'account_id' => $account->id,
        ]);

        return $change;
    }
}
