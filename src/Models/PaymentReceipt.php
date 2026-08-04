<?php

declare(strict_types=1);

namespace Kraite\Core\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kraite\Core\Abstracts\BaseModel;

/**
 * Idempotency ledger for each NOWPayments payment or repeated deposit.
 *
 * @property int $id
 * @property int $payment_id
 * @property string $gateway_payment_id
 * @property string|null $parent_gateway_payment_id
 * @property string $credited_amount
 */
final class PaymentReceipt extends BaseModel
{
    protected $casts = [
        'credited_amount' => 'decimal:4',
    ];

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
