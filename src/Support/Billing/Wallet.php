<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Billing;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Kraite\Core\Models\User;
use Kraite\Core\Models\WalletTransaction;

/**
 * Single point of mutation for a user's wallet balance and
 * subscription renewal anchor.
 *
 * Every credit / debit / renewal routes through here so the ledger
 * stays consistent with `users.wallet_balance_usdt` and
 * `users.subscription_renews_at`. Each public mutator is wrapped in
 * a transaction with a row-level lock on the user, so concurrent
 * webhook + cron + admin-tool calls cannot race.
 *
 * Insufficient-funds debits raise `InsufficientFundsException`. The
 * caller decides what to do (closing-mode, reject admin attempt, etc).
 */
final class Wallet
{
    /**
     * Credit USDT to a user's wallet and record one ledger row.
     *
     * @param  array<string, mixed>  $meta
     */
    public function credit(
        User $user,
        float $amount,
        string $type,
        string $description,
        array $meta = [],
    ): WalletTransaction {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Credit amount must be positive.');
        }

        return DB::transaction(function () use ($user, $amount, $type, $description, $meta) {
            $locked = User::lockForUpdate()->findOrFail($user->id);
            $newBalance = (float) $locked->wallet_balance_usdt + $amount;

            $locked->wallet_balance_usdt = $newBalance;
            $locked->save();

            $user->setRawAttributes($locked->getAttributes(), sync: true);

            return WalletTransaction::create([
                'user_id' => $locked->id,
                'type' => $type,
                'amount_usdt' => $amount,
                'balance_after' => $newBalance,
                'description' => $description,
                'meta' => $meta ?: null,
            ]);
        });
    }

    /**
     * Debit USDT from a user's wallet and record one ledger row.
     *
     * @param  array<string, mixed>  $meta
     *
     * @throws InsufficientFundsException when the user has less than $amount.
     */
    public function debit(
        User $user,
        float $amount,
        string $type,
        string $description,
        array $meta = [],
    ): WalletTransaction {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Debit amount must be positive.');
        }

        return DB::transaction(function () use ($user, $amount, $type, $description, $meta) {
            $locked = User::lockForUpdate()->findOrFail($user->id);
            $current = (float) $locked->wallet_balance_usdt;

            if ($current < $amount) {
                throw new InsufficientFundsException(
                    "User {$locked->id} has {$current} USDT, debit of {$amount} rejected.",
                );
            }

            $newBalance = $current - $amount;

            $locked->wallet_balance_usdt = $newBalance;
            $locked->save();

            $user->setRawAttributes($locked->getAttributes(), sync: true);

            return WalletTransaction::create([
                'user_id' => $locked->id,
                'type' => $type,
                'amount_usdt' => -$amount,
                'balance_after' => $newBalance,
                'description' => $description,
                'meta' => $meta ?: null,
            ]);
        });
    }

    /**
     * Run a monthly renewal: debit one tier rate from the wallet and
     * push the renewal anchor forward atomically. Used by the daily
     * midnight cron and by the read-only-unlock path on a top-up.
     *
     * The new renewal anchor defaults to (current anchor + 1 month),
     * or (now + 1 month) when no anchor is set. Callers can override
     * via $newRenewsAt — the read-only-unlock path passes
     * (now + 1 month - 1 day) so the day of unlock counts as day 1
     * of the new cycle.
     *
     * @throws InsufficientFundsException when wallet < tier rate.
     * @throws InvalidArgumentException when the user has no tier.
     */
    public function runRenewal(
        User $user,
        ?CarbonInterface $newRenewsAt = null,
    ): WalletTransaction {
        return DB::transaction(function () use ($user, $newRenewsAt) {
            $locked = User::lockForUpdate()->findOrFail($user->id);
            $tier = $locked->subscription;

            if ($tier === null) {
                throw new InvalidArgumentException(
                    "User {$locked->id} has no subscription tier.",
                );
            }

            $rate = (float) $tier->monthly_rate_usdt;

            if ($rate <= 0) {
                throw new InvalidArgumentException(
                    "Subscription tier {$tier->canonical} has no monthly rate.",
                );
            }

            $current = (float) $locked->wallet_balance_usdt;

            if ($current < $rate) {
                throw new InsufficientFundsException(
                    "User {$locked->id} has {$current} USDT, monthly debit of {$rate} rejected.",
                );
            }

            $newAnchor = $newRenewsAt ?? (
                $locked->subscription_renews_at !== null
                    ? $locked->subscription_renews_at->copy()->addMonth()
                    : now()->addMonth()
            );

            $newBalance = $current - $rate;

            $locked->wallet_balance_usdt = $newBalance;
            $locked->subscription_renews_at = $newAnchor;
            $locked->save();

            $user->setRawAttributes($locked->getAttributes(), sync: true);

            return WalletTransaction::create([
                'user_id' => $locked->id,
                'type' => WalletTransaction::TYPE_DEBIT_SUBSCRIPTION,
                'amount_usdt' => -$rate,
                'balance_after' => $newBalance,
                'description' => sprintf(
                    'Monthly %s renewal · renews %s',
                    $tier->name,
                    $newAnchor->toDateString(),
                ),
                'meta' => [
                    'subscription_id' => $tier->id,
                    'subscription_canonical' => $tier->canonical,
                    'rate_at_run' => $rate,
                    'renews_at_after' => $newAnchor->toIso8601String(),
                ],
            ]);
        });
    }
}
