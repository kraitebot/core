<?php

declare(strict_types=1);

namespace Kraite\Core\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;
use Kraite\Core\Abstracts\BaseModel;
use Kraite\Core\Concerns\Order\HandlesChanges;
use Kraite\Core\Concerns\Order\HasGetters;
use Kraite\Core\Concerns\Order\HasScopes;
use Kraite\Core\Concerns\Order\HasStatuses;
use Kraite\Core\Concerns\Order\HasTradingActions;
use Kraite\Core\Concerns\Order\InteractsWithApis;
use StepDispatcher\Models\Step;

/**
 * @property int $id
 * @property int $position_id
 * @property string|null $uuid
 * @property string|null $client_order_id
 * @property string $type
 * @property string|null $status
 * @property string|null $reference_status
 * @property string|null $reference_price
 * @property string|null $reference_quantity
 * @property string|null $original_price
 * @property string|null $original_quantity
 * @property string|null $exchange_order_id
 * @property string|null $quantity
 * @property string|null $price
 * @property string|null $filled_quantity
 * @property CarbonInterface|null $filled_at
 * @property string|null $side
 * @property string|null $position_side
 * @property-read Position|null $position
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
        'original_price' => 'string',
        'original_quantity' => 'string',
        'is_algo' => 'boolean',
    ];

    /**
     * Atomically claim an order slot and persist the order.
     *
     * The parent position lock serializes the observer's active-order count
     * with the insert across every worker and database connection.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function createForPosition(array $attributes): self
    {
        return DB::transaction(function () use ($attributes): self {
            $position = Position::query()
                ->whereKey($attributes['position_id'] ?? null)
                ->lockForUpdate()
                ->first();

            $order = new self;
            $order->fill($attributes);

            if ($position !== null) {
                $order->setRelation('position', $position);
            }

            $order->save();

            return $order;
        });
    }

    public function steps()
    {
        return $this->morphMany(Step::class, 'relatable');
    }

    public function apiRequestLogs()
    {
        return $this->morphMany(ApiRequestLog::class, 'relatable');
    }

    /**
     * @return BelongsTo<Position, $this>
     */
    public function position(): BelongsTo
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
        return $value === null ? null : $this->removeTrailingZeros($value);
    }

    public function getQuantityAttribute($value): ?string
    {
        return $value === null ? null : $this->removeTrailingZeros($value);
    }

    public function getOriginalPriceAttribute($value): ?string
    {
        return $value === null ? null : $this->removeTrailingZeros($value);
    }

    public function getOriginalQuantityAttribute($value): ?string
    {
        return $value === null ? null : $this->removeTrailingZeros($value);
    }

    /**
     * Trim the decimal tail of a DECIMAL(20,8) string coming off the DB.
     *
     * Kept as a pure string operation: casting through `(float)` introduces
     * IEEE-754 round-trip artifacts on large integer-ish quantities (e.g.
     * `692672.00000000` printed back as `692672.0000000001`), which leaks
     * into the UI as a phantom drift between our DB and the exchange.
     */
    private function removeTrailingZeros(mixed $value): string
    {
        $str = (string) $value;

        if (! str_contains($str, '.')) {
            return $str;
        }

        return mb_rtrim(mb_rtrim($str, '0'), '.');
    }
}
