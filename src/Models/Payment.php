<?php

declare(strict_types=1);

namespace Kraite\Core\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Kraite\Core\Abstracts\BaseModel;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $nowpayments_payment_id
 * @property string $order_id
 * @property string|null $pay_currency
 * @property string|null $pay_amount
 * @property string $price_amount
 * @property string|null $outcome_amount
 * @property string $credited_amount
 * @property string $outcome_currency
 * @property string $status
 * @property string|null $invoice_url
 * @property Carbon|null $credited_at
 * @property array<string, mixed>|null $raw_payload
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class Payment extends BaseModel
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_WAITING = 'waiting';

    public const STATUS_CONFIRMING = 'confirming';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_SENDING = 'sending';

    public const STATUS_PARTIALLY_PAID = 'partially_paid';

    public const STATUS_FINISHED = 'finished';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUS_EXPIRED = 'expired';

    /**
     * Statuses that result in USDT credited to the user wallet.
     * "partially_paid" is included — under our balance-based model
     * we credit whatever the gateway reports as received.
     */
    public const CREDITABLE_STATUSES = [
        self::STATUS_FINISHED,
        self::STATUS_PARTIALLY_PAID,
    ];

    protected $casts = [
        'pay_amount' => 'decimal:12',
        'price_amount' => 'decimal:4',
        'outcome_amount' => 'decimal:4',
        'credited_amount' => 'decimal:4',
        'credited_at' => 'datetime',
        'raw_payload' => 'array',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<PaymentReceipt, $this> */
    public function receipts(): HasMany
    {
        return $this->hasMany(PaymentReceipt::class);
    }

    public function isCredited(): bool
    {
        return $this->credited_at !== null;
    }

    public function isCreditable(): bool
    {
        return in_array($this->status, self::CREDITABLE_STATUSES, true);
    }
}
