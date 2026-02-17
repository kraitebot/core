<?php

declare(strict_types=1);

namespace Kraite\Core\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kraite\Core\Abstracts\BaseModel;

/**
 * Maps token naming conventions between Binance and other exchanges.
 *
 * Binance token names are the reference since TAAPI indicators use Binance data.
 * This table allows translating Binance tokens to equivalent names on other exchanges.
 *
 * @property int $id
 * @property string $binance_token The Binance token name (reference for TAAPI indicators)
 * @property string $other_token The equivalent token name on the other exchange
 * @property int $other_api_system_id The api_system_id of the other exchange
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class TokenMapper extends BaseModel
{
    protected $fillable = [
        'binance_token',
        'other_token',
        'other_api_system_id',
    ];

    /**
     * The exchange (ApiSystem) that uses the other_token naming convention.
     */
    public function apiSystem(): BelongsTo
    {
        return $this->belongsTo(ApiSystem::class, 'other_api_system_id');
    }
}
