<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $trialDaysBySubscription = DB::table('subscriptions')->pluck('trial_days', 'id');

        DB::table('users')
            ->whereNotNull('subscription_id')
            ->whereNotNull('trial_started_at')
            ->whereNull('subscription_renews_at')
            ->orderBy('id')
            ->chunkById(200, function ($users) use ($trialDaysBySubscription): void {
                foreach ($users as $user) {
                    $trialDays = $user->trial_days_override
                        ?? $trialDaysBySubscription->get($user->subscription_id);

                    if ($trialDays === null) {
                        continue;
                    }

                    DB::table('users')
                        ->where('id', $user->id)
                        ->update([
                            'subscription_renews_at' => Carbon::parse($user->trial_started_at)
                                ->addDays(max(0, (int) $trialDays)),
                        ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally retained: the backfilled timestamps are valid billing
        // state and cannot be distinguished safely from later user changes.
    }
};
