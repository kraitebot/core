<?php

declare(strict_types=1);

namespace Kraite\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Kraite\Core\Abstracts\BaseModel;
use Kraite\Core\Concerns\TradeConfiguration\HasGetters;
use Kraite\Core\Concerns\TradeConfiguration\HasScopes;

/**
 * @property int $id
 * @property bool $is_default
 * @property string $canonical
 * @property string|null $description
 * @property int $least_timeframe_index_to_change_indicator
 * @property int $fast_trade_position_duration_seconds
 * @property int $fast_trade_position_closed_age_seconds
 * @property bool $disable_exchange_symbol_from_negative_pnl_position
 * @property string $min_account_balance
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class TradeConfiguration extends BaseModel
{
    use HasFactory;
    use HasGetters;
    use HasScopes;

    protected $table = 'trade_configuration';

    protected $casts = [
        'is_default' => 'boolean',
        'disable_exchange_symbol_from_negative_pnl_position' => 'boolean',
    ];

    public function accounts()
    {
        return $this->hasMany(Account::class);
    }

    protected static function newFactory()
    {
        return \Kraite\Core\Database\Factories\TradeConfigurationFactory::new();
    }
}
