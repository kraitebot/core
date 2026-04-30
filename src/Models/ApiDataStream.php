<?php

declare(strict_types=1);

namespace Kraite\Core\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Kraite\Core\Abstracts\BaseModel;

/**
 * @property int $id
 * @property int $account_id
 * @property int $api_system_id
 * @property string $raw_event_type
 * @property string $event_type
 * @property string|null $exchange_order_id
 * @property string|null $client_order_id
 * @property string|null $symbol
 * @property string|null $status
 * @property string|null $normalized_status
 * @property string|null $price
 * @property string|null $average_price
 * @property string|null $original_quantity
 * @property string|null $filled_quantity
 * @property string|null $last_filled_price
 * @property string|null $last_filled_quantity
 * @property Carbon|null $event_time
 * @property Carbon $received_at
 * @property array $raw_payload
 * @property string $idempotency_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * Raw audit log of every frame received from any exchange's authenticated
 * private WebSocket. Generic across exchanges; per-exchange differentiation
 * lives on the api_system_id FK, not in separate tables. Decimal columns
 * stay as strings to preserve full precision (matches Order::price /
 * Order::quantity casting).
 */
class ApiDataStream extends BaseModel
{
    protected $table = 'api_data_stream';

    protected $guarded = ['id'];

    protected $casts = [
        'event_time' => 'datetime',
        'received_at' => 'datetime',
        'raw_payload' => 'array',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function apiSystem(): BelongsTo
    {
        return $this->belongsTo(ApiSystem::class);
    }
}
