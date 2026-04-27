<?php

declare(strict_types=1);

namespace Kraite\Core\Models;

use Kraite\Core\Abstracts\BaseModel;
use Kraite\Core\Enums\RegimeBand;

/**
 * One row per hourly BSCS recompute. Append-only.
 *
 * @property int $id
 * @property \Illuminate\Support\Carbon $computed_at
 * @property int $bscs_score
 * @property string $bscs_band
 * @property string $vol_expansion_value
 * @property bool $vol_expansion_fired
 * @property string $range_blowout_value
 * @property bool $range_blowout_fired
 * @property string $corr_regime_value
 * @property bool $corr_regime_fired
 * @property string $rejection_pct_value
 * @property bool $rejection_pct_fired
 * @property string $fut_vol_value
 * @property bool $fut_vol_fired
 * @property string $btc_close
 * @property array<string, mixed> $inputs_meta
 *
 * @see \Kraite\Core\Support\MarketRegime\RegimeCalculator
 * @see ~/docs/kraite/black-swan-logic.md
 */
final class MarketRegimeSnapshot extends BaseModel
{
    protected $table = 'market_regime_snapshots';

    protected $casts = [
        'computed_at' => 'datetime',
        'bscs_score' => 'integer',
        'vol_expansion_fired' => 'boolean',
        'range_blowout_fired' => 'boolean',
        'corr_regime_fired' => 'boolean',
        'rejection_pct_fired' => 'boolean',
        'fut_vol_fired' => 'boolean',
        'inputs_meta' => 'array',
    ];

    public function band(): RegimeBand
    {
        return RegimeBand::from($this->bscs_band);
    }
}
