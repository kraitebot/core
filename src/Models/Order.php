<?php

declare(strict_types=1);

namespace Kraite\Core\Models;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Kraite\Core\Abstracts\BaseModel;
use Kraite\Core\Concerns\Order\HandlesChanges;
use Kraite\Core\Concerns\Order\HasGetters;
use Kraite\Core\Concerns\Order\HasScopes;
use Kraite\Core\Concerns\Order\HasStatuses;
use Kraite\Core\Concerns\Order\HasTradingActions;
use Kraite\Core\Concerns\Order\InteractsWithApis;

/**
 * @property int $id
 * @property int $position_id
 * @property string $type
 * @property string $status
 * @property string|null $reference_status
 * @property string|null $reference_price
 * @property string|null $reference_quantity
 * @property string|null $exchange_order_id
 * @property string|null $quantity
 * @property string|null $price
 * @property string|null $filled_quantity
 * @property string|null $side
 * @property Position $position
 * @property bool $is_algo
 *
 * @method \Kraite\Core\Models\ExchangeSymbol exchangeSymbol()
 */
final class Order extends BaseModel
{
    use HandlesChanges;
    use HasGetters;
    use HasScopes;
    use HasStatuses;
    use HasTradingActions;
    use InteractsWithApis;

    protected $casts = [
        'opened_at' => 'datetime',
        'filled_at' => 'datetime',

        'price' => 'string',
        'quantity' => 'string',
        'reference_price' => 'string',
        'reference_quantity' => 'string',
        'is_algo' => 'boolean',
    ];

    public function steps()
    {
        return $this->morphMany(Step::class, 'relatable');
    }

    public function apiRequestLogs()
    {
        return $this->morphMany(ApiRequestLog::class, 'relatable');
    }

    public function position()
    {
        return $this->belongsTo(Position::class);
    }

    public function ordersHistory()
    {
        return $this->hasMany(self::class, 'order_id');
    }

    public function apiSnapshots(): MorphMany
    {
        return $this->morphMany(ApiSnapshot::class, 'responsable');
    }

    public function getPriceAttribute($value): ?string
    {
        return $value === null ? null : $this->removeTrailingZeros((float) $value);
    }

    public function getQuantityAttribute($value): ?string
    {
        return $value === null ? null : $this->removeTrailingZeros((float) $value);
    }

    private function removeTrailingZeros(float $number): string
    {
        // Ensure the number is normalized as a string, no scientific notation.
        $normalized = number_format($number, 10, '.', '');  // Force a fixed decimal string

        return mb_rtrim(mb_rtrim($normalized, '0'), '.');  // Remove trailing zeros and the dot
    }
}
