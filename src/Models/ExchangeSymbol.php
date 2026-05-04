<?php

declare(strict_types=1);

namespace Kraite\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Kraite\Core\Abstracts\BaseModel;
use Kraite\Core\Concerns\ExchangeSymbol\HasAccessors;
use Kraite\Core\Concerns\ExchangeSymbol\HasScopes;
use Kraite\Core\Concerns\ExchangeSymbol\HasStatuses;
use Kraite\Core\Concerns\ExchangeSymbol\HasTradingComputations;
use Kraite\Core\Concerns\ExchangeSymbol\InteractsWithApis;
use Kraite\Core\Concerns\ExchangeSymbol\SendsNotifications;
use Kraite\Core\Database\Factories\ExchangeSymbolFactory;

/**
 * @property int $id
 * @property string $token
 * @property string $quote
 * @property string|null $asset
 * @property int|null $symbol_id
 * @property array{cmc_api_called?: bool, taapi_verified?: bool, has_taapi_data?: bool} $api_statuses
 * @property int $api_system_id
 * @property bool|null $is_manually_enabled
 * @property bool $has_no_indicator_data
 * @property bool $has_price_trend_misalignment
 * @property bool $has_early_direction_change
 * @property bool $has_invalid_indicator_direction
 * @property string|null $direction
 * @property float|null $percentage_gap_long
 * @property float|null $percentage_gap_short
 * @property int $price_precision
 * @property int $quantity_precision
 * @property float|null $min_notional
 * @property float|null $kraken_min_order_size
 * @property float|null $kucoin_lot_size
 * @property float|null $kucoin_multiplier
 * @property float $tick_size
 * @property float|null $min_price
 * @property float|null $max_price
 * @property int|null $total_limit_orders
 * @property array|null $limit_quantity_multipliers
 * @property float|null $disable_on_price_spike_percentage
 * @property int|null $price_spike_cooldown_hours
 * @property int|null $delivery_ts_ms
 * @property \Illuminate\Support\Carbon|null $delivery_at
 * @property \Illuminate\Support\Carbon|null $tradeable_at
 * @property array|null $symbol_information
 * @property array|null $leverage_brackets
 * @property mixed $indicators_values
 * @property string|null $indicators_timeframe
 * @property \Illuminate\Support\Carbon|null $indicators_synced_at
 * @property array<string, float>|null $btc_correlation_pearson
 * @property array<string, float>|null $btc_correlation_spearman
 * @property array<string, float>|null $btc_correlation_rolling
 * @property array<string, float>|null $btc_correlation_stability
 * @property array<string, float>|null $btc_elasticity_long
 * @property array<string, float>|null $btc_elasticity_short
 * @property bool|null $overlaps_with_binance
 * @property bool $is_marked_for_delisting
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read Symbol|null $symbol
 * @property-read ApiSystem $apiSystem
 * @property-read string|null $parsed_trading_pair
 * @property-read string|null $parsed_trading_pair_extended
 * @property-read bool $is_tradeable
 */
final class ExchangeSymbol extends BaseModel
{
    use HasAccessors;
    use HasFactory;
    use HasScopes;
    use HasStatuses;
    use HasTradingComputations;
    use InteractsWithApis;
    use SendsNotifications;

    protected $casts = [
        'is_manually_enabled' => 'boolean',
        'was_backtesting_approved' => 'boolean',
        'has_no_indicator_data' => 'boolean',
        'has_price_trend_misalignment' => 'boolean',
        'has_early_direction_change' => 'boolean',
        'has_invalid_indicator_direction' => 'boolean',
        'overlaps_with_binance' => 'boolean',
        'is_marked_for_delisting' => 'boolean',

        'api_statuses' => 'array',
        'symbol_information' => 'array',
        'leverage_brackets' => 'array',
        'indicators' => 'array',
        'indicators_values' => 'array',
        'limit_quantity_multipliers' => 'array',

        'btc_correlation_pearson' => 'array',
        'btc_correlation_spearman' => 'array',
        'btc_correlation_rolling' => 'array',
        'btc_correlation_stability' => 'array',
        'btc_elasticity_long' => 'array',
        'btc_elasticity_short' => 'array',

        'indicators_synced_at' => 'datetime',
        'delivery_at' => 'datetime',
        'tradeable_at' => 'datetime',

        'delivery_ts_ms' => 'integer',
    ];

    public function steps(): MorphMany
    {
        return $this->morphMany(Step::class, 'relatable');
    }

    public function candles(): HasMany
    {
        return $this->hasMany(Candle::class);
    }

    public function leverageBrackets(): HasMany
    {
        return $this->hasMany(LeverageBracket::class);
    }

    public function apiRequestLogs(): MorphMany
    {
        return $this->morphMany(ApiRequestLog::class, 'relatable');
    }

    public function symbol(): BelongsTo
    {
        return $this->belongsTo(Symbol::class);
    }

    public function apiSystem(): BelongsTo
    {
        return $this->belongsTo(ApiSystem::class);
    }

    /**
     * Sidecar 1:1 carrying the hot mark-price pair. The price
     * daemon writes here on every WS tick; reads go through the
     * `mark_price` / `mark_price_synced_at` accessors that proxy
     * to this row first, falling back to the legacy column on
     * `exchange_symbols` during the cutover-soak window.
     *
     * @return HasOne<ExchangeSymbolPrice, $this>
     */
    public function priceRow(): HasOne
    {
        return $this->hasOne(ExchangeSymbolPrice::class);
    }

    /**
     * Read mark_price from the sidecar table when present; fall
     * back to the legacy column on `exchange_symbols` otherwise.
     * Both are normalised to the sidecar's 8-decimal precision so
     * consumers get a consistent format regardless of which side
     * served the read (the legacy column on the parent table is
     * decimal(36,18), the sidecar is decimal(20,8)). Once the
     * cutover-soak migration drops the legacy column, the
     * fallback is removed in a follow-up commit and this
     * accessor reads only from the relation.
     */
    public function getMarkPriceAttribute($value): ?string
    {
        $sidecar = $this->priceRow;

        if ($sidecar !== null && $sidecar->mark_price !== null) {
            return self::normalisePriceString((string) $sidecar->mark_price);
        }

        return $value !== null ? self::normalisePriceString((string) $value) : null;
    }

    /**
     * Read mark_price_synced_at from the sidecar table when
     * present; fall back to the legacy column otherwise.
     * Returns a Carbon-flavoured datetime — Eloquent may produce
     * either `Illuminate\Support\Carbon` or `Carbon\CarbonImmutable`
     * depending on the cast configuration; the broader
     * `Carbon\CarbonInterface` accommodates both.
     */
    public function getMarkPriceSyncedAtAttribute($value): ?\Carbon\CarbonInterface
    {
        $sidecar = $this->priceRow;

        if ($sidecar !== null && $sidecar->mark_price_synced_at !== null) {
            return $sidecar->mark_price_synced_at;
        }

        if ($value === null) {
            return null;
        }

        return $value instanceof \Carbon\CarbonInterface
            ? $value
            : \Illuminate\Support\Carbon::parse($value);
    }

    /**
     * Normalise a decimal price string to the sidecar's 8-decimal
     * representation. The legacy `exchange_symbols.mark_price`
     * column is decimal(36,18); reads through that path return
     * an over-padded string ("7.770000000000000000") that doesn't
     * match the sidecar's 8-decimal format. Trim back to the
     * canonical 8-decimal so consumers get one shape.
     */
    private static function normalisePriceString(string $value): string
    {
        if (! is_numeric($value)) {
            return $value;
        }

        return number_format((float) $value, 8, '.', '');
    }

    protected static function newFactory(): ExchangeSymbolFactory
    {
        return ExchangeSymbolFactory::new();
    }
}
