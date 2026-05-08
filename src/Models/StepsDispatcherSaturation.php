<?php

declare(strict_types=1);

namespace Kraite\Core\Models;

use Kraite\Core\Abstracts\BaseModel;

/**
 * @property int $id
 * @property string $group
 * @property \Illuminate\Support\Carbon $bucket_started_at
 * @property int $ticks_observed
 * @property int $ticks_capped
 * @property int $ticks_capped_with_leftover
 * @property int $total_dispatched
 * @property int $max_pending_after
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class StepsDispatcherSaturation extends BaseModel
{
    protected $table = 'steps_dispatcher_saturation';

    protected $guarded = ['id'];

    protected $casts = [
        'bucket_started_at' => 'datetime',
        'ticks_observed' => 'integer',
        'ticks_capped' => 'integer',
        'ticks_capped_with_leftover' => 'integer',
        'total_dispatched' => 'integer',
        'max_pending_after' => 'integer',
    ];

    /**
     * Saturation as a percentage. 100% means every tick this bucket was at
     * the cap AND had more Pending work waiting — the unambiguous signal
     * that the dispatcher is the actual bottleneck. Below 100% means the
     * cap is not what's stopping useful work from being promoted; adding
     * more groups will not move the needle.
     */
    public function getSaturationPctAttribute(): float
    {
        if ($this->ticks_observed === 0) {
            return 0.0;
        }

        return round(($this->ticks_capped_with_leftover / $this->ticks_observed) * 100, 2);
    }
}
