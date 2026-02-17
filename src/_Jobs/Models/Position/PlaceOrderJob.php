<?php

declare(strict_types=1);

namespace Kraite\Core\_Jobs\Models\Position;

use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Models\Position;

/**
 * PlaceOrderJob
 *
 * Places an order on the exchange for opening a position.
 *
 * TODO: Convert to BaseApiableJob when implementing actual exchange API call.
 */
final class PlaceOrderJob extends BaseQueueableJob
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
        // TODO: Implement exchange API call for placing order
        // This should be converted to BaseApiableJob with computeApiable() when ready
        return [
            'position_id' => $this->position->id,
            'message' => 'Order placed (placeholder)',
        ];
    }
}
