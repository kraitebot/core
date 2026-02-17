<?php

declare(strict_types=1);

namespace Kraite\Core\Models;

use Kraite\Core\Abstracts\BaseModel;

final class IndicatorHistory extends BaseModel
{
    protected $fillable = [
        'exchange_symbol_id',
        'indicator_id',
        'timeframe',
        'timestamp',
        'data',
        'conclusion',
    ];

    protected $casts = [
        'exchange_symbol_id' => 'int',
        'indicator_id' => 'int',
        'data' => 'array',
    ];

    public function indicator()
    {
        return $this->belongsTo(Indicator::class);
    }

    public function exchangeSymbol()
    {
        return $this->belongsTo(ExchangeSymbol::class);
    }
}
