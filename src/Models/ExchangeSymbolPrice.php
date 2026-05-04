<?php

declare(strict_types=1);

namespace Kraite\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 1:1 sidecar of `exchange_symbols` carrying the hot mark-price
 * pair (`mark_price`, `mark_price_synced_at`). The price daemon
 * writes here on every WS tick (1-second cadence, ~500 rows
 * per batch). Splitting the columns out of the parent table
 * means the daemon's bulk UPDATE no longer holds row locks on
 * `exchange_symbols` — every other writer of that table
 * (indicator pipeline, ConcludeSymbolsDirectionCommand burst at
 * :30, api_request_logs INSERTs that touch the relation) can
 * proceed concurrently without blocking the daemon's reactphp
 * loop.
 *
 * Read path: `ExchangeSymbol::$mark_price` /
 * `mark_price_synced_at` accessors proxy to this row, so every
 * existing call site (~15 sites across jobs / concerns /
 * indicators / dashboard) is zero-touch. The accessor falls
 * back to the legacy column on `exchange_symbols` for symbols
 * whose price row hasn't landed yet (transitional safety
 * net during the rollout). After the cutover soak, the
 * legacy columns are dropped via a separate migration and
 * the fallback is removed.
 *
 * @property int $id
 * @property int $exchange_symbol_id
 * @property string|null $mark_price
 * @property \Illuminate\Support\Carbon|null $mark_price_synced_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read ExchangeSymbol $exchangeSymbol
 */
final class ExchangeSymbolPrice extends Model
{
    protected $table = 'exchange_symbol_prices';

    protected $guarded = [];

    protected $casts = [
        'mark_price_synced_at' => 'datetime',
    ];

    /** @return BelongsTo<ExchangeSymbol, $this> */
    public function exchangeSymbol(): BelongsTo
    {
        return $this->belongsTo(ExchangeSymbol::class);
    }
}
