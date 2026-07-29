<?php

declare(strict_types=1);

namespace Kraite\Core\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Kraite\Core\Abstracts\BaseModel;

/**
 * One record from the exchange's income ledger — a realized-PnL booking, a
 * commission charge, or a funding payment — stamped with the moment the
 * exchange booked it rather than the moment the position closed.
 *
 * @property int $id
 * @property int $account_id
 * @property string $tran_id
 * @property string $income_type
 * @property string|null $symbol
 * @property string $income
 * @property string|null $asset
 * @property Carbon $occurred_at
 */
final class AccountIncome extends BaseModel
{
    /**
     * Income types that together make up what a trader calls "profit for the
     * day": the trade result, the fee paid to make it, and the funding
     * carried while holding it. Transfers and other wallet movements are
     * deliberately absent — money paid in is not performance.
     *
     * @var array<int, string>
     */
    public const TRADING_TYPES = ['REALIZED_PNL', 'COMMISSION', 'FUNDING_FEE'];

    protected $fillable = [
        'account_id',
        'tran_id',
        'income_type',
        'symbol',
        'income',
        'asset',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'income' => 'decimal:8',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** Only the records that constitute trading performance. */
    public function scopeTrading($query)
    {
        return $query->whereIn('income_type', self::TRADING_TYPES);
    }
}
