<?php

declare(strict_types=1);

namespace Kraite\Core\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kraite\Core\Abstracts\BaseModel;

/**
 * ForbiddenHostname
 *
 * Tracks IP addresses that are blocked from making API calls.
 *
 * Types:
 * - ip_not_whitelisted: User forgot to whitelist IP on their API key (user-fixable)
 * - ip_rate_limited: Temporary rate limit ban (auto-recovers after forbidden_until)
 * - ip_banned: Permanent IP ban for ALL accounts (contact exchange support)
 * - account_blocked: Account-specific API key issue (user regenerates key)
 *
 * @property int $id
 * @property int $api_system_id
 * @property int|null $account_id
 * @property string $ip_address
 * @property string $type
 * @property \Illuminate\Support\Carbon|null $forbidden_until
 * @property string|null $error_code
 * @property string|null $error_message
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class ForbiddenHostname extends BaseModel
{
    // Type constants for the 4 blocking cases
    public const TYPE_IP_NOT_WHITELISTED = 'ip_not_whitelisted';

    public const TYPE_IP_RATE_LIMITED = 'ip_rate_limited';

    public const TYPE_IP_BANNED = 'ip_banned';

    public const TYPE_ACCOUNT_BLOCKED = 'account_blocked';

    /**
     * @return BelongsTo<ApiSystem, ForbiddenHostname>
     */
    public function apiSystem(): BelongsTo
    {
        return $this->belongsTo(ApiSystem::class);
    }

    /**
     * @return BelongsTo<Account, ForbiddenHostname>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Check if this ban is currently active.
     * Returns false if forbidden_until has passed (ban expired).
     */
    public function isActive(): bool
    {
        // If no expiry set, it's permanent/user-fixable
        if ($this->forbidden_until === null) {
            return true;
        }

        // If expiry is in the future, still active
        return $this->forbidden_until->isFuture();
    }

    /**
     * Check if this is a system-wide ban (affects ALL accounts).
     */
    public function isSystemWide(): bool
    {
        return $this->account_id === null;
    }

    /**
     * Check if this is a temporary ban that auto-recovers.
     */
    public function isTemporary(): bool
    {
        return $this->type === self::TYPE_IP_RATE_LIMITED && $this->forbidden_until !== null;
    }

    /**
     * Check if this requires user action to resolve.
     */
    public function requiresUserAction(): bool
    {
        return in_array($this->type, [
            self::TYPE_IP_NOT_WHITELISTED,
            self::TYPE_ACCOUNT_BLOCKED,
        ], strict: true);
    }

    /**
     * Check if this requires contacting exchange support.
     */
    public function requiresExchangeSupport(): bool
    {
        return $this->type === self::TYPE_IP_BANNED;
    }

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'forbidden_until' => 'datetime',
        ];
    }
}
