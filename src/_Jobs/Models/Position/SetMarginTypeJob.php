<?php

declare(strict_types=1);

namespace Kraite\Core\_Jobs\Models\Position;

use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Models\Position;

/**
 * SetMarginTypeJob
 *
 * Sets the margin type (isolated/cross) for a position on the exchange.
 * This is typically the first step in preparing a position for trading.
 *
 * TODO: Convert to BaseApiableJob when implementing actual exchange API call.
 */
final class SetMarginTypeJob extends BaseQueueableJob
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
        // TODO: Implement exchange API call for setting margin type
        // This should be converted to BaseApiableJob with computeApiable() when ready
        return [
            'position_id' => $this->position->id,
            'message' => 'Margin type set (placeholder)',
        ];
    }
}
