<?php

declare(strict_types=1);

namespace Kraite\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Kraite\Core\Abstracts\BaseModel;
use Kraite\Core\Concerns\Symbol\InteractsWithApis;
use Kraite\Core\Database\Factories\SymbolFactory;
use StepDispatcher\Models\Step;

/**
 * @property int $id
 * @property string $token
 * @property string $name
 * @property string|null $description
 * @property string|null $site_url
 * @property string|null $image_url
 * @property int|null $cmc_id
 * @property int|null $cmc_ranking
 * @property bool $is_stable_coin
 * @property string|null $cmc_category
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class Symbol extends BaseModel
{
    use HasFactory;
    use InteractsWithApis;

    protected $casts = [
        'is_stable_coin' => 'boolean',
    ];

    public function steps()
    {
        return $this->morphMany(Step::class, 'relatable');
    }

    public function apiRequestLogs()
    {
        return $this->morphMany(ApiRequestLog::class, 'relatable');
    }

    public function exchangeSymbols()
    {
        return $this->hasMany(ExchangeSymbol::class);
    }

    protected static function newFactory(): SymbolFactory
    {
        return SymbolFactory::new();
    }
}
