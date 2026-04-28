<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Cronjobs;

use Illuminate\Support\Facades\Log;
use Kraite\Core\Models\User;
use Kraite\Core\Models\WalletTransaction;
use Kraite\Core\Support\Billing\InsufficientFundsException;
use Kraite\Core\Support\Billing\Wallet;
use Kraite\Core\Support\NotificationService;
use StepDispatcher\Support\BaseCommand;
use Throwable;

/**
 * Daily subscription deduction.
 *
 * Runs once per day. For every billable user, attempts to debit the
 * tier's daily rate from their wallet via the Wallet ledger service.
 * If the user is mid-trial, the debit is skipped (the trial window
 * grants free usage). If the wallet can't cover the debit, no row is
 * written and the user enters closing-mode for the day — surfaced
 * later by the trading guards which read `User::isInClosingMode()`.
 *
 * Deduct-before-usage semantic: we run early in the day and the user
 * either pays for that day up-front or sits the day out. Top-ups that
 * arrive later in the day take effect on the NEXT cron tick.
 *
 * Live tier rates: each user's daily rate is read fresh from
 * `subscriptions.daily_rate_usdt` every run. A price change applied
 * five minutes before this command fires is honoured immediately.
 */
final class DeductSubscriptionsCommand extends BaseCommand
{
    protected $signature = 'kraite:cron-deduct-subscriptions
                            {--dry-run : Report what would be debited without writing}
                            {--output : Display command output (silent by default)}';

    protected $description = 'Debit each billable user the daily subscription rate. Skips trial-active users; flags closing-mode users without writing.';

    public function handle(Wallet $wallet): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $billable = User::with('subscription')
            ->whereNotNull('subscription_id')
            ->where('is_active', true)
            ->get();

        if ($billable->isEmpty()) {
            $this->verboseInfo('No billable users.');

            return self::SUCCESS;
        }

        $debited = 0;
        $skippedTrial = 0;
        $closingMode = 0;
        $totalDebitedUsdt = 0.0;

        foreach ($billable as $user) {
            $tier = $user->subscription;

            if ($tier === null) {
                continue;
            }

            $rate = (float) $tier->daily_rate_usdt;

            if ($rate <= 0) {
                continue;
            }

            if ($user->isTrialActive()) {
                $skippedTrial++;
                $this->verboseInfo("user {$user->id} ({$user->email}) — trial active, no debit");

                continue;
            }

            if ($dryRun) {
                $this->verboseInfo("DRY RUN — would debit {$rate} from user {$user->id} ({$user->email})");
                $debited++;
                $totalDebitedUsdt += $rate;

                continue;
            }

            try {
                $wallet->debit(
                    user: $user,
                    amount: $rate,
                    type: WalletTransaction::TYPE_DEBIT_SUBSCRIPTION,
                    description: sprintf(
                        'Daily %s deduction · %s',
                        $tier->name,
                        now()->toDateString(),
                    ),
                    meta: [
                        'subscription_id' => $tier->id,
                        'subscription_canonical' => $tier->canonical,
                        'rate_at_run' => $rate,
                    ],
                );

                $debited++;
                $totalDebitedUsdt += $rate;
                $this->verboseInfo("user {$user->id} ({$user->email}) — debited {$rate}");

                $remainingDays = $user->walletRunwayDays();
                if ($remainingDays !== null && $remainingDays < 7) {
                    $this->notifyLowBalance($user, $rate, $remainingDays);
                }
            } catch (InsufficientFundsException) {
                $closingMode++;
                $this->verboseWarn("user {$user->id} ({$user->email}) — insufficient funds, closing-mode");
                $this->notifyClosingMode($user, $rate);
            } catch (Throwable $e) {
                Log::error('subscription_debit_failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
                $this->verboseWarn("user {$user->id} — error: {$e->getMessage()}");
            }
        }

        $this->verboseInfo(sprintf(
            'Done. debited=%d (%.4f USDT) · trial=%d · closing-mode=%d',
            $debited,
            $totalDebitedUsdt,
            $skippedTrial,
            $closingMode,
        ));

        return self::SUCCESS;
    }

    private function notifyLowBalance(User $user, float $rate, int $runwayDays): void
    {
        try {
            NotificationService::send(
                user: $user,
                canonical: 'subscription_low_balance',
                referenceData: [
                    'runway_days' => $runwayDays,
                    'balance_usdt' => (float) $user->wallet_balance_usdt,
                    'daily_rate_usdt' => $rate,
                ],
            );
        } catch (Throwable $e) {
            Log::warning('[DeductSubscriptions] low_balance notification failed', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function notifyClosingMode(User $user, float $rate): void
    {
        try {
            NotificationService::send(
                user: $user,
                canonical: 'subscription_closing_mode',
                referenceData: [
                    'balance_usdt' => (float) $user->wallet_balance_usdt,
                    'daily_rate_usdt' => $rate,
                ],
            );
        } catch (Throwable $e) {
            Log::warning('[DeductSubscriptions] closing_mode notification failed', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
