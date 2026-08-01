<?php

declare(strict_types=1);

namespace Kraite\Core\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Kraite\Core\Abstracts\BaseModel;
use Kraite\Core\Concerns\Position\HasAccessors;
use Kraite\Core\Concerns\Position\HasGetters;
use Kraite\Core\Concerns\Position\HasScopes;
use Kraite\Core\Concerns\Position\HasStatuses;
use Kraite\Core\Concerns\Position\HasTradingActions;
use Kraite\Core\Concerns\Position\InteractsWithApis;
use Kraite\Core\Database\Factories\PositionFactory;

/**
 * @property int $id
 * @property string $status
 * @property string $direction
 * @property string|null $max_pain
 * @property int $total_limit_orders
 * @property bool $was_waped
 * @property Account $account
 * @property ExchangeSymbol $exchangeSymbol
 * @property-read Collection<int, Order> $orders
 */
final class Position extends BaseModel
{
    use HasAccessors;
    use HasFactory;
    use HasGetters;
    use HasScopes;
    use HasStatuses;
    use HasTradingActions;
    use InteractsWithApis;

    protected $casts = [
        'was_fast_traded' => 'boolean',
        'was_waped' => 'boolean',

        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'waped_at' => 'datetime',

        'indicators_values' => 'array',

        'quantity' => 'string',
        'max_pain' => 'string',

        'bscs_score' => 'integer',
    ];

    public function logMutators(): array
    {
        return [
            'exchange_symbol_id' => static function ($model, $old, $new, $type) {
                $model->refresh();

                return $model->parsed_trading_pair;
            },

            'account_id' => static function ($model, $old, $new, $type) {
                $model->refresh();

                return $model->account->user->name;
            },
        ];
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
     * @return BelongsTo<ExchangeSymbol, $this>
     */
    public function exchangeSymbol(): BelongsTo
    {
        return $this->belongsTo(ExchangeSymbol::class);
    }

    public function apiSnapshots(): MorphMany
    {
        return $this->morphMany(ApiSnapshot::class, 'responsable');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function tradeConfiguration()
    {
        return $this->belongsTo(TradeConfiguration::class);
    }

    protected static function newFactory()
    {
        return PositionFactory::new();
    }
}
