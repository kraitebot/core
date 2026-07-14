<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Billing;

use Kraite\Core\Models\User;

/**
 * Read-only facade over a user's subscription state. Encapsulates the
 * "is this user's trial / paid window currently valid?" logic so
 * callers (chiefly `CreatePositionsCommand` via
 * `Account::isReadyToTrade`) never have to chain `isPaused() &&
 * !isInClosingMode() && ...` checks by hand.
 *
 * The polarity rule: `isActive() === ! $user->isInClosingMode()`.
 * `isInClosingMode()` already encodes paused / trial-active / no-
 * renewal-anchor / past-renewal-anchor on the User model — this
 * facade just inverts it and exposes the supporting state in named
 * methods.
 */
final class SubscriptionState
{
    public function __construct(private readonly User $user) {}

    /**
     * True when the user is allowed to trade from a subscription POV:
     * trial in progress, a paid renewal anchor is still in the future,
     * or a free tier applies; paused users are never active.
     *
     * Pair with `User::can_trade` (user/bot pause switch) and the
     * account-level `Account::isReadyToTrade` to get the full trading
     * readiness decision.
     */
    public function isActive(): bool
    {
        return ! $this->user->isInClosingMode();
    }

    /**
     * True when the user is voluntarily paused (`subscription_paused_at`
     * is set). Always wins over trial / wallet coverage.
     */
    public function isPaused(): bool
    {
        return $this->user->isPaused();
    }

    /**
     * True when the user is inside the trial window
     * (`trial_started_at + effectiveTrialDays()` is in the future).
     */
    public function isInTrial(): bool
    {
        return $this->user->isTrialActive();
    }

    /**
     * True when the trial period was started AND has elapsed.
     * Distinct from "trial never started" so callers can identify
     * who consumed their trial vs who never engaged.
     */
    public function trialIsExpired(): bool
    {
        return $this->user->isTrialExpired();
    }

    /**
     * True when the wallet balance covers the current monthly tier
     * rate (or there is no tier, i.e. trial-only / no rate set).
     */
    public function coversNextRenewal(): bool
    {
        return $this->user->subscriptionCoversNextRenewal();
    }

    /**
     * USDT short of the next monthly renewal. Zero when the wallet
     * already covers the rate (or no tier is set).
     */
    public function shortfallUsdt(): float
    {
        return $this->user->renewalShortfallUsdt();
    }
}
