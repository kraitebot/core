<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Billing;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Kraite\Core\Models\User;
use Kraite\Core\Models\WalletTransaction;

/**
 * Single point of mutation for a user's wallet balance.
 *
 * Every credit / debit / adjustment routes through here so the ledger
 * stays consistent with `users.wallet_balance_usdt`. The pair of
 * writes is wrapped in a transaction with a row-level lock on the
 * user, so concurrent webhook + cron + admin-tool calls cannot race.
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
     * Bonus tier matching a top-up amount, in percent.
     */
    public static function bonusPercentFor(float $topUpAmount): int
    {
        return match (true) {
            $topUpAmount >= 500 => 15,
            $topUpAmount >= 100 => 10,
            $topUpAmount >= 50 => 5,
            default => 0,
        };
    }
}

