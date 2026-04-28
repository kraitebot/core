<?php

declare(strict_types=1);

namespace Kraite\Core\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Kraite\Core\Abstracts\BaseModel;

/**
 * @property int $id
 * @property string $name
 * @property string $canonical
 * @property string|null $description
 * @property string $daily_rate_usdt
 * @property int $trial_days
 * @property int|null $max_accounts
 * @property int|null $max_exchanges
 * @property string|null $max_balance
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class Subscription extends BaseModel
{
    protected $casts = [
        'is_active' => 'boolean',
        'max_accounts' => 'integer',
        'max_exchanges' => 'integer',
        'max_balance' => 'decimal:2',
        'daily_rate_usdt' => 'decimal:4',
        'trial_days' => 'integer',
    ];

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Check if this subscription has unlimited accounts.
     */
    public function hasUnlimitedAccounts(): bool
    {
        return $this->max_accounts === null;
    }

    /**
     * Check if this subscription has unlimited exchanges.
     */
    public function hasUnlimitedExchanges(): bool
    {
        return $this->max_exchanges === null;
    }

    /**
     * Check if this subscription has unlimited balance.
     */
    public function hasUnlimitedBalance(): bool
    {
        return $this->max_balance === null;
    }
}
