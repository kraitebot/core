<?php

declare(strict_types=1);

namespace Kraite\Core\Observers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Kraite\Core\Jobs\Lifecycles\Account\PreparePositionsOpeningJob;
use Kraite\Core\Models\Account;
use StepDispatcher\Models\Step;
use StepDispatcher\Support\Steps;
use Throwable;

final class AccountObserver
{
    public function creating(Account $model): void
    {
        $model->uuid ??= Str::uuid()->toString();
    }

    /**
     * Event-on-activation hook Bruno asked for during the private-beta
     * onboarding elicitation. When an account's `can_trade` flips from
     * false to true (typically after a successful connectivity test
     * during registration, or an operator restoring a previously-failed
     * account), kick off `PreparePositionsOpeningJob` immediately
     * instead of waiting up to 3 minutes for the next
     * `kraite:cron-create-positions` tick.
     *
     * Cuts the wall-clock time from "user completes registration" to
     * "first trade attempt" by up to 3 minutes on the happy path.
     *
     * Safety:
     *   - Only fires on the genuine false→true transition (no infinite
     *     loop on every save).
     *   - Re-runs `Account::isReadyToTrade()` to confirm all 3 gates
     *     pass — `can_trade` alone is not enough.
     *   - Dedups against the existing `attemptOpeningPositionsForAccount`
     *     pending-step check, so a concurrent cron tick can't double-
     *     dispatch.
     *   - Wrapped in `Steps::usingPrefix('trading', ...)` to match the
     *     cron path — every position-opening step lives in the trading
     *     prefixed table set.
     *   - Failure to dispatch is logged + swallowed so the original
     *     `Account::update` never aborts because of an event-on-
     *     activation issue.
     */
    public function updated(Account $account): void
    {
        if (! $account->wasChanged('can_trade')) {
            return;
        }

        if (! $account->can_trade) {
            return;
        }

        if (! $account->isReadyToTrade()) {
            return;
        }

        try {
            Steps::usingPrefix('trading', function () use ($account): void {
                if (Step::hasLiveWorkflow($account, PreparePositionsOpeningJob::class)) {
                    return;
                }

                Step::create([
                    'class' => PreparePositionsOpeningJob::class,
                    'queue' => 'cronjobs',
                    'relatable_type' => $account->getMorphClass(),
                    'relatable_id' => $account->getKey(),
                    'arguments' => ['accountId' => $account->id],
                ]);
            });
        } catch (Throwable $e) {
            Log::channel('jobs')->warning('[AccountObserver] event-on-activation PreparePositionsOpeningJob dispatch failed — swallowed so the Account::update succeeds; next kraite:cron-create-positions tick will pick it up', [
                'account_id' => $account->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
