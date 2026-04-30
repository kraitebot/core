<?php

declare(strict_types=1);

namespace Kraite\Core\Models;

use Kraite\Core\Abstracts\BaseModel;

/**
 * @property int $id
 * @property string $canonical
 * @property string $display_name
 * @property int $sort_order
 * @property bool $is_active
 * @property string|null $min_amount_override
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
final class TopUpCoin extends BaseModel
{
    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'min_amount_override' => 'decimal:6',
    ];

    public static function active(): \Illuminate\Database\Eloquent\Collection
    {
        return self::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('display_name')
            ->get();
    }
}
