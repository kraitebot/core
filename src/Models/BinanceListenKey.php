<?php

declare(strict_types=1);

namespace Kraite\Core\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Kraite\Core\Abstracts\BaseModel;

/**
 * @property int $id
 * @property int $account_id
 * @property string $listen_key
 * @property Carbon|null $last_created_at
 * @property Carbon|null $last_keep_alive_at
 * @property string|null $last_keep_alive_status
 * @property int $failure_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * Per-account Binance listenKey state. One row per Binance account that
 * participates in the user data stream daemon. Drives both the daemon's
 * connection lifecycle and the keepalive cron's PUT cadence. listen_key
 * is encrypted at rest because anyone with it can subscribe to the
 * account's order/balance events on Binance's edge.
 */
class BinanceListenKey extends BaseModel
{
    protected $table = 'binance_listen_keys';

    protected $guarded = ['id'];

    protected $casts = [
        'listen_key' => 'encrypted',
        'last_created_at' => 'datetime',
        'last_keep_alive_at' => 'datetime',
        'failure_count' => 'integer',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
