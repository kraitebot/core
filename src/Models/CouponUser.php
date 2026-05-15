<?php

declare(strict_types=1);

namespace Kraite\Core\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Custom pivot for the `coupon_user` relationship. Behaves like a
 * regular Eloquent model so observer hooks (Phase 2 — mail dispatch
 * on `created`) fire reliably.
 *
 * Active-state derivation lives here: `isActive()` consults the pivot
 * window AND the parent coupon's per-user redemption cap.
 *
 * @property int $id
 * @property int $user_id
 * @property int $coupon_id
 * @property \Illuminate\Support\Carbon|null $valid_from
 * @property \Illuminate\Support\Carbon|null $valid_until
 * @property int $usage_count
 * @property \Illuminate\Support\Carbon $attached_at
 * @property \Illuminate\Support\Carbon|null $last_used_at
 * @property-read Coupon|null $coupon
 */
final class CouponUser extends Pivot
{
    public $incrementing = true;

    public $timestamps = true;

    protected $table = 'coupon_user';

    protected $casts = [
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'usage_count' => 'integer',
        'attached_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    /**
     * Pivot is active when its own window is open AND the parent
     * coupon's per-user cap hasn't been reached. Caller is responsible
     * for asserting the parent coupon's global active state separately
     * (the two layers are deliberately independent so an operator can
     * pause a coupon globally without touching any pivot rows).
     */
    public function isActive(): bool
    {
        $now = now();

        if ($this->valid_from !== null && $now->lt($this->valid_from)) {
            return false;
        }

        if ($this->valid_until !== null && $now->gt($this->valid_until)) {
            return false;
        }

        $coupon = $this->coupon ?? Coupon::find($this->coupon_id);

        if ($coupon !== null
            && $coupon->max_usage_per_user !== null
            && $this->usage_count >= $coupon->max_usage_per_user
        ) {
            return false;
        }

        return true;
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class, 'coupon_id');
    }
}
