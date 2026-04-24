<?php

declare(strict_types=1);

namespace Kraite\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Kraite\Core\Abstracts\BaseModel;
use Kraite\Core\Concerns\ApiSystem\HasCooldown;
use Kraite\Core\Concerns\ApiSystem\HasScopes;
use Kraite\Core\Concerns\ApiSystem\InteractsWithApis;

/**
 * @property int $id
 * @property bool $is_exchange
 * @property string $name
 * @property int $recvwindow_margin
 * @property string $canonical
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property bool $should_restart_websocket
 * @property string|null $websocket_class
 * @property \Illuminate\Support\Carbon|null $cooldown_until
 */
final class ApiSystem extends BaseModel
{
    use HasCooldown;
    use HasFactory;
    use HasScopes;
    use InteractsWithApis;

    protected $casts = [
        'cooldown_until' => 'datetime',
    ];

    public function steps(): MorphMany
    {
        return $this->morphMany(Step::class, 'relatable');
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    public function exchangeSymbols(): HasMany
    {
        return $this->hasMany(ExchangeSymbol::class);
    }

    public function positions(): HasManyThrough
    {
        return $this->hasManyThrough(Position::class, Account::class);
    }

    /**
     * Token mappings where this exchange uses different token names than Binance.
     */
    public function tokenMappers(): HasMany
    {
        return $this->hasMany(TokenMapper::class, 'other_api_system_id');
    }

    protected static function newFactory()
    {
        return \Kraite\Core\Database\Factories\ApiSystemFactory::new();
    }
}
