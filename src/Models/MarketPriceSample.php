<?php

declare(strict_types=1);

namespace Kraite\Core\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One per-minute mark-price sample for a BSCS reference-basket token.
 * Written and pruned by DetectMarketShockJob; consumed by its
 * live-window cascade evaluation. Rolling ~3h retention — this table
 * is a detection buffer, never a historical record.
 */
final class MarketPriceSample extends Model
{
    protected $fillable = [
        'token',
        'price',
        'sampled_at',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:8',
            'sampled_at' => 'datetime',
        ];
    }
}
