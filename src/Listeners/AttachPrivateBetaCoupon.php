<?php

declare(strict_types=1);

namespace Kraite\Core\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Kraite\Core\Events\UserEmailConfirmed;
use Kraite\Core\Models\Coupon;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\User;

/**
 * On `UserEmailConfirmed`, attach the private-beta 25% coupon to the
 * newly-verified user IFF:
 *
 *   1. The global `kraite.in_private_beta` flag is true.
 *   2. The user exists.
 *   3. The user does not already have the private-beta coupon
 *      attached (idempotent — re-firing the event on the same user
 *      MUST be a no-op).
 *
 * Implements `ShouldQueue` because the event fires from kraite.com
 * (the marketing site) but this attach work runs on the ingestion
 * worker — the queued shape is what carries the work across processes
 * via the shared Redis queue. Without `ShouldQueue` the listener
 * would only run in the dispatching process, and kraite.com has no
 * `Coupon` or `coupon_user` access path at runtime.
 *
 * The pivot row is permanent (per the system-wide rule that pivots
 * never detach). The attachment write is wrapped in a transaction so
 * concurrent fires of the event for the same user race-safely produce
 * at most one pivot row.
 *
 * The "discount applied" mail is intentionally NOT fired here — the
 * Phase-2 `CouponUserObserver` watches pivot `created` events and
 * dispatches the canonical from a single place.
 */
final class AttachPrivateBetaCoupon implements ShouldQueue
{
    /**
     * Default queue (Redis). Falls onto the cronjobs queue so it
     * shares the same Horizon supervisor block as other onboarding
     * housekeeping work.
     */
    public string $queue = 'cronjobs';

    public function handle(UserEmailConfirmed $event): void
    {
        $kraite = Kraite::find(1);

        if ($kraite === null || ! (bool) $kraite->in_private_beta) {
            return;
        }

        $user = User::find($event->userId);

        if ($user === null) {
            return;
        }

        $coupon = Coupon::where('slug', Coupon::SLUG_PRIVATE_BETA_25)->first();

        if ($coupon === null) {
            return;
        }

        DB::transaction(function () use ($user, $coupon): void {
            $alreadyAttached = DB::table('coupon_user')
                ->where('user_id', $user->id)
                ->where('coupon_id', $coupon->id)
                ->lockForUpdate()
                ->exists();

            if ($alreadyAttached) {
                return;
            }

            DB::table('coupon_user')->insert([
                'user_id' => $user->id,
                'coupon_id' => $coupon->id,
                'valid_from' => null,
                'valid_until' => null,
                'usage_count' => 0,
                'attached_at' => now(),
                'last_used_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }
}
