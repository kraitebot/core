<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Billing;

use Kraite\Core\Models\User;

/**
 * Top-level facade Bruno asked for: `$user->billing()->...`. Holds
 * one user reference and hands out scoped sub-facades:
 *
 *   - `subscription()` — `SubscriptionState` (active / trial / coverage / shortfall)
 *
 * Phase-2 backlog (not in this release):
 *   - `wallet()` — `Wallet` with `topUp($amount)` + `rollback($txUuid)`
 *   - `coupons()` — query over the user's active coupon pivots
 *
 * The shape stays open-for-extension so future billing concerns (e.g.
 * payouts, refunds) can hang off `$user->billing()` without polluting
 * the User model surface.
 */
final class BillingManager
{
    public function __construct(private readonly User $user) {}

    /**
     * Subscription-state facade — see `SubscriptionState` for the
     * available checks.
     */
    public function subscription(): SubscriptionState
    {
        return new SubscriptionState($this->user);
    }
}
