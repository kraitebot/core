<?php

declare(strict_types=1);

namespace Kraite\Core\Support\MarketRegime;

use Kraite\Core\Models\Account;

/**
 * Creates immutable BSCS contexts from the latest persisted global state.
 */
final class BscsManager
{
    public function current(): BscsState
    {
        return BscsState::current();
    }

    public function forAccount(Account $account, ?BscsState $state = null): BscsAccountContext
    {
        return new BscsAccountContext(
            account: $account,
            state: $state ?? $this->current(),
        );
    }
}
