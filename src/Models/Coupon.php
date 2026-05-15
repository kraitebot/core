<?php

declare(strict_types=1);

namespace Kraite\Core\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Kraite\Core\Support\Math;

/**
 * A Coupon is a stand-alone discount template that becomes real only
 * when attached to a user via the `coupon_user` pivot. The Coupon row
 * owns the shape (type + value), the global active window
 * (`valid_from` / `valid_until`), the global attachment cap
 * (`max_usage` — distinct users that may ever attach this), and the
 * per-user redemption cap (`max_usage_per_user` — how many times a
 * single user may apply this when topping up).
 *
 * Active checks are derived. There is no "status" column; the active
 * state for any moment in time is read from the timestamps + caps +
 * the `is_active` hard switch.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string|null $description
 * @property string $type
 * @property string $value
 * @property \Illuminate\Support\Carbon|null $valid_from
 * @property \Illuminate\Support\Carbon|null $valid_until
 * @property int|null $max_usage
 * @property int|null $max_usage_per_user
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
final class Coupon extends Model
{
    public const TYPE_PERCENTAGE = 'percentage';

    public const TYPE_ABSOLUTE = 'absolute';

    public const SLUG_PRIVATE_BETA_25 = 'private_beta_25';

    protected $casts = [
        'value' => 'decimal:4',
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'max_usage' => 'integer',
        'max_usage_per_user' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * Singleton-style accessor for the canonical private-beta coupon.
     * Throws if the seed migration hasn't run.
     */
    public static function privateBeta(): self
    {
        return self::where('slug', self::SLUG_PRIVATE_BETA_25)->firstOrFail();
    }

    /**
     * Users who have ever been attached to this coupon (pivot is
     * permanent — even exhausted / expired attachments stay).
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'coupon_user')
            ->using(CouponUser::class)
            ->withPivot([
                'valid_from',
                'valid_until',
                'usage_count',
                'attached_at',
                'last_used_at',
            ])
            ->withTimestamps();
    }

    /**
     * Constrain to coupons that pass the global active gates *right
     * now*: `is_active` flag is true, current time is within
     * `[valid_from, valid_until]` (nulls treated as open ends), and
     * the global `max_usage` attachment budget has not been reached.
     *
     * @param  Builder<Coupon>  $query
     * @return Builder<Coupon>
     */
    public function scopeGloballyActive(Builder $query): Builder
    {
        $now = now();

        return $query
            ->where('is_active', true)
            ->where(function (Builder $q) use ($now): void {
                $q->whereNull('valid_from')->orWhere('valid_from', '<=', $now);
            })
            ->where(function (Builder $q) use ($now): void {
                $q->whereNull('valid_until')->orWhere('valid_until', '>=', $now);
            })
            ->where(function (Builder $q): void {
                $q->whereNull('max_usage')
                    ->orWhereRaw('(select count(*) from coupon_user where coupon_user.coupon_id = coupons.id) < coupons.max_usage');
            });
    }

    /**
     * Compute the bonus value this coupon would emit for the given
     * source top-up amount, expressed as a BCMath decimal string.
     * Percentage coupons return `value * source / 100`; absolute
     * coupons return `value` (regardless of source).
     */
    public function bonusFor(string $sourceAmount): string
    {
        if ($this->type === self::TYPE_ABSOLUTE) {
            return Math::add('0', (string) $this->value);
        }

        $product = Math::mul((string) $this->value, $sourceAmount);

        return Math::div($product, '100');
    }

    /**
     * Same constraints as `scopeGloballyActive`, evaluated against the
     * loaded model instance (in-memory). Useful for non-DB callers.
     */
    public function isGloballyActive(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $now = now();

        if ($this->valid_from !== null && $now->lt($this->valid_from)) {
            return false;
        }

        if ($this->valid_until !== null && $now->gt($this->valid_until)) {
            return false;
        }

        if ($this->max_usage !== null && $this->users()->count() >= $this->max_usage) {
            return false;
        }

        return true;
    }
}
