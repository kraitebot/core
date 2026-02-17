<?php

declare(strict_types=1);

namespace Kraite\Core\_Jobs\Models\Position;

use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Models\Position;

/**
 * SetLeverageJob
 *
 * Sets the leverage for a position on the exchange.
 *
 * TODO: Convert to BaseApiableJob when implementing actual exchange API call.
 */
final class SetLeverageJob extends BaseQueueableJob
{
    public Position $position;

    public function __construct(int $positionId)
    {
        $this->position = Position::findOrFail($positionId);
    }

    public function relatable()
    {
        return $this->position;
    }

    public function compute()
    {
        // TODO: Implement exchange API call for setting leverage
        // This should be converted to BaseApiableJob with computeApiable() when ready
        return [
            'position_id' => $this->position->id,
            'message' => 'Leverage set (placeholder)',
        ];
    }
}
