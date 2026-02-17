<?php

declare(strict_types=1);

namespace Kraite\Core\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kraite\Core\Abstracts\BaseModel;
use Kraite\Core\Database\Factories\CandleFactory;

/**
 * @property int $id
 * @property int $exchange_symbol_id
 * @property string $timeframe
 * @property string $open
 * @property string $high
 * @property string $low
 * @property string $close
 * @property string $volume
 * @property int $timestamp
 * @property \Illuminate\Support\Carbon|null $candle_time_utc
 * @property \Illuminate\Support\Carbon|null $candle_time_local
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
final class Candle extends BaseModel
{
    use HasFactory;

    protected $casts = [
        'candle_time_utc' => 'datetime',
        'candle_time_local' => 'datetime',
    ];

    /**
     * Set candle_time_utc and auto-compute candle_time_local.
     */
    public function setCandleTimeUtcAttribute(mixed $value): void
    {
        $this->attributes['candle_time_utc'] = $value;

        if ($value !== null) {
            $utcTime = Carbon::parse($value, 'UTC');
            $this->attributes['candle_time_local'] = $utcTime->setTimezone(config('app.timezone'))->toDateTimeString();
        }
    }

    public function exchangeSymbol(): BelongsTo
    {
        return $this->belongsTo(ExchangeSymbol::class);
    }

    protected static function newFactory(): CandleFactory
    {
        return CandleFactory::new();
    }
}
