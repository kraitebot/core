<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the four notification canonicals consumed by the subscription
 * billing system:
 *
 *  - subscription_low_balance: fires when wallet runway drops below 7
 *    days at the user's current daily rate.
 *  - subscription_closing_mode: fires the day a billable user can no
 *    longer cover their daily rate; new opens blocked, existing
 *    positions wind down naturally.
 *  - subscription_trial_ending: fires shortly before a trial expires
 *    so the user can top up before closing-mode kicks in.
 *  - subscription_topup_confirmed: fires when a top-up payment is
 *    received and credited (incl. bonus) to the user's wallet.
 *
 * Idempotent via updateOrInsert on canonical.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $rows = [
            [
                'canonical' => 'subscription_low_balance',
                'title' => 'Wallet runway below 7 days',
                'description' => 'User wallet balance covers fewer than 7 days at the current daily rate',
                'detailed_description' => 'Sent by kraite:cron-deduct-subscriptions when, after the daily debit, the remaining wallet balance covers fewer than 7 days at the user\'s current tier rate. Single shot per user per low-balance window; suppressed by cache until the user tops up enough to clear the threshold.',
                'usage_reference' => 'kraite:cron-deduct-subscriptions',
                'default_severity' => 'high',
                'verified' => 1,
                'is_active' => true,
                'cache_duration' => 86400,
                'cache_key' => json_encode(['user']),
            ],
            [
                'canonical' => 'subscription_closing_mode',
                'title' => 'Wallet exhausted — closing-positions mode',
                'description' => 'User wallet cannot cover the daily debit; new opens blocked, existing positions wind down naturally',
                'detailed_description' => 'Sent by kraite:cron-deduct-subscriptions on the first day a billable user has insufficient balance for their daily debit. Trading guards block new opens; existing positions complete their lifecycle to TP/SL. Re-arms once the user tops up and successfully covers a daily debit again.',
                'usage_reference' => 'kraite:cron-deduct-subscriptions',
                'default_severity' => 'critical',
                'verified' => 1,
                'is_active' => true,
                'cache_duration' => 3600,
                'cache_key' => json_encode(['user']),
            ],
            [
                'canonical' => 'subscription_trial_ending',
                'title' => 'Trial ending soon',
                'description' => 'User\'s 7-day trial expires within 24 hours — top up to keep trading without interruption',
                'detailed_description' => 'Sent by kraite:cron-deduct-subscriptions when a trial-active user has fewer than 24 hours of trial window remaining. Single shot per user per trial cycle.',
                'usage_reference' => 'kraite:cron-deduct-subscriptions',
                'default_severity' => 'info',
                'verified' => 1,
                'is_active' => true,
                'cache_duration' => 86400,
                'cache_key' => json_encode(['user']),
            ],
            [
                'canonical' => 'subscription_topup_confirmed',
                'title' => 'Top-up confirmed',
                'description' => 'Payment received and credited (incl. bonus) to user wallet',
                'detailed_description' => 'Fired when a top-up event is finalised — currently triggered by admin credit-adjustment; will also be triggered by NOWPayments webhook in v2 when the gateway integration ships.',
                'usage_reference' => 'admin credit-adjustment + NOWPayments webhook (v2)',
                'default_severity' => 'info',
                'verified' => 1,
                'is_active' => true,
                'cache_duration' => 60,
                'cache_key' => json_encode(['user', 'transaction']),
            ],
        ];

        foreach ($rows as $row) {
            DB::table('notifications')->updateOrInsert(
                ['canonical' => $row['canonical']],
                array_merge($row, [
                    'created_at' => $now,
                    'updated_at' => $now,
                ]),
            );
        }
    }

    public function down(): void
    {
        DB::table('notifications')->whereIn('canonical', [
            'subscription_low_balance',
            'subscription_closing_mode',
            'subscription_trial_ending',
            'subscription_topup_confirmed',
        ])->delete();
    }
};
