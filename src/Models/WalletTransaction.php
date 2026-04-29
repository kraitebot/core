<?php

declare(strict_types=1);

namespace Kraite\Core\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kraite\Core\Abstracts\BaseModel;

/**
 * Append-only ledger for every credit and debit on a user's wallet.
 *
 * Every state change to `users.wallet_balance_usdt` MUST go through a
 * row written here. The `balance_after` snapshot makes disputes
 * resolvable by reading the ledger top-to-bottom.
 *
 * @property int $id
 * @property int $user_id
 * @property string $type
 * @property string $amount_usdt
 * @property string $balance_after
 * @property string $description
 * @property array<string, mixed>|null $meta
 * @property \Illuminate\Support\Carbon $created_at
 */
final class WalletTransaction extends BaseModel
{
    public const TYPE_DEBIT_SUBSCRIPTION = 'debit_subscription';

    public const TYPE_CREDIT_TOPUP = 'credit_topup';

    public const TYPE_CREDIT_TOPUP_BONUS = 'credit_topup_bonus';

    public const TYPE_CREDIT_PRORATE_REFUND = 'credit_prorate_refund';

    public const TYPE_CREDIT_ADMIN = 'credit_admin';

    public const TYPE_DEBIT_ADMIN = 'debit_admin';

    public $timestamps = false;

    protected $casts = [
        'amount_usdt' => 'decimal:4',
        'balance_after' => 'decimal:4',
        'meta' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isCredit(): bool
    {
        return (float) $this->amount_usdt > 0;
    }

    public function isDebit(): bool
    {
        return (float) $this->amount_usdt < 0;
    }
}
