<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Cronjobs;

use Illuminate\Support\Facades\Log;
use Kraite\Core\Models\User;
use Kraite\Core\Support\Billing\InsufficientFundsException;
use Kraite\Core\Support\Billing\Wallet;
use Kraite\Core\Support\Math;
use Kraite\Core\Support\NotificationService;
use StepDispatcher\Support\BaseCommand;
use Throwable;

/**
 * Daily monthly-renewal cron.
 *
 * Runs once per day (midnight). Three responsibilities, processed in
 * one pass against the billable user set:
 *
 *  1. Pre-warn each user whose `subscription_renews_at` is exactly 7
 *     days away AND whose wallet won't cover the monthly rate.
 *     Fires `subscription_low_balance`.
 *
 *  2. Pre-warn each trial user whose trial expires in exactly 2
 *     days AND whose wallet won't cover the first renewal.
 *     Fires `subscription_trial_ending` (one-shot per user lifetime).
 *
 *  3. Renew each user whose `subscription_renews_at` is now or in
 *     the past (and not paused, not trial-active). Successful
 *     renewal debits one month and pushes the anchor +1 month.
 *     Failure (wallet short) fires `subscription_closing_mode` and
 *     leaves the anchor in the past — accounts now read flag-true
 *     for `User::isInClosingMode()` and the trading guards block
 *     new opens.
 *
 * Live tier rates: each user's monthly rate is read fresh from
 * `subscriptions.monthly_rate_usdt` every run. A price change applied
 * minutes before this command fires is honoured immediately.
 */
final class RenewSubscriptionsCommand extends BaseCommand
{
    protected $signature = 'kraite:cron-renew-subscriptions
                            {--dry-run : Report what would be renewed without writing}
                            {--output : Display command output (silent by default)}';

    protected $description = 'Process monthly subscription renewals. Fires pre-warnings 7 days out + 2 days before trial end. Debits the monthly rate or flips the user to read-only on insufficient funds.';

    public function handle(Wallet $wallet): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $billable = User::with('subscription')
            ->where('is_active', true)
            ->whereNotNull('subscription_id')
            ->whereNull('subscription_paused_at')
            ->get();

        if ($billable->isEmpty()) {
            $this->verboseInfo('No billable users.');

            return self::SUCCESS;
        }

        $renewed = 0;
        $closingMode = 0;
        $preWarned = 0;
        $trialWarned = 0;
        $totalDebitedUsdt = 0.0;

        foreach ($billable as $user) {
            $tier = $user->subscription;

            if ($tier === null) {
                continue;
            }

            $rate = (string) $tier->monthly_rate_usdt;

            if (Math::lte($rate, '0')) {
                continue;
            }

            // Trial-ending pre-warning (2 days before expiry, wallet short).
            if ($user->isTrialActive()) {
                if ($this->trialExpiresInDays($user, 2) && ! $user->subscriptionCoversNextRenewal()) {
                    $this->notifyTrialEnding($user, $rate);
                    $trialWarned++;
                }

                continue;
            }

            // Low-balance pre-warning (renewal in 7 days, wallet short).
            if ($this->renewalInDays($user, 7) && ! $user->subscriptionCoversNextRenewal()) {
                $this->notifyLowBalance($user, $rate);
                $preWarned++;
            }

            // Renewal due?
            if ($user->subscription_renews_at === null) {
                continue;
            }

            if ($user->subscription_renews_at->isFuture()) {
                continue;
            }

            if ($dryRun) {
                $this->verboseInfo("DRY RUN — would renew user {$user->id} ({$user->email}) at {$rate} USDT/month");
                $renewed++;
                $totalDebitedUsdt += $rate;

                continue;
            }

            try {
                $wallet->runRenewal($user);
                $renewed++;
                $totalDebitedUsdt += $rate;
                $this->verboseInfo("user {$user->id} ({$user->email}) — renewed at {$rate}, anchor → {$user->subscription_renews_at->toDateString()}");
            } catch (InsufficientFundsException) {
                $closingMode++;
                $this->verboseWarn("user {$user->id} ({$user->email}) — renewal failed, read-only mode");
                $this->notifyClosingMode($user, $rate);
            } catch (Throwable $e) {
                Log::error('subscription_renewal_failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
                $this->verboseWarn("user {$user->id} — error: {$e->getMessage()}");
            }
        }

        $this->verboseInfo(sprintf(
            'Done. renewed=%d (%.4f USDT) · pre-warned=%d · trial-warned=%d · closing-mode=%d',
            $renewed,
            $totalDebitedUsdt,
            $preWarned,
            $trialWarned,
            $closingMode,
        ));

        return self::SUCCESS;
    }

    private function renewalInDays(User $user, int $days): bool
    {
        if ($user->subscription_renews_at === null) {
            return false;
        }

        return now()->copy()->addDays($days)->isSameDay($user->subscription_renews_at);
    }

    private function trialExpiresInDays(User $user, int $days): bool
    {
        if ($user->trial_started_at === null) {
            return false;
        }

        $trialDays = $user->effectiveTrialDays();

        if ($trialDays <= 0) {
            return false;
        }

        $trialEnd = $user->trial_started_at->copy()->addDays($trialDays);

        return now()->copy()->addDays($days)->isSameDay($trialEnd);
    }

    private function notifyLowBalance(User $user, string $rate): void
    {
        try {
            NotificationService::send(
                user: $user,
                canonical: 'subscription_low_balance',
                referenceData: [
                    'renews_at' => $user->subscription_renews_at?->toDateString(),
                    'balance_usdt' => (string) $user->wallet_balance_usdt,
                    'monthly_rate_usdt' => $rate,
                    'shortfall_usdt' => $user->renewalShortfallUsdt(),
                ],
            );
        } catch (Throwable $e) {
            Log::warning('[RenewSubscriptions] low_balance notification failed', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function notifyTrialEnding(User $user, string $rate): void
    {
        try {
            NotificationService::send(
                user: $user,
                canonical: 'subscription_trial_ending',
                referenceData: [
                    'trial_started_at' => $user->trial_started_at?->toDateString(),
                    'trial_days' => $user->effectiveTrialDays(),
                    'balance_usdt' => (string) $user->wallet_balance_usdt,
                    'monthly_rate_usdt' => $rate,
                    'shortfall_usdt' => $user->renewalShortfallUsdt(),
                ],
            );
        } catch (Throwable $e) {
            Log::warning('[RenewSubscriptions] trial_ending notification failed', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function notifyClosingMode(User $user, string $rate): void
    {
        try {
            NotificationService::send(
                user: $user,
                canonical: 'subscription_closing_mode',
                referenceData: [
                    'balance_usdt' => (string) $user->wallet_balance_usdt,
                    'monthly_rate_usdt' => $rate,
                    'shortfall_usdt' => $user->renewalShortfallUsdt(),
                ],
            );
        } catch (Throwable $e) {
            Log::warning('[RenewSubscriptions] closing_mode notification failed', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
