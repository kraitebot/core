<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns\Order;

use Illuminate\Support\Str;
use Kraite\Core\Jobs\Lifecycles\Positions\ApplyWAPJob;
use Kraite\Core\Jobs\Models\Position\UpdatePositionStatusJob;
use StepDispatcher\Models\Step;

trait HandlesChanges
{
    public function processWAPChanges(): void
    {
        // In case the position is still watching, just skip it.
        if ($this->status === 'watching') {
            return;
        }

        // Only handle LIMIT orders that were filled.
        if ($this->type === 'LIMIT' && $this->status === 'FILLED') {
            $uuid = Str::uuid()->toString();
            $childBlockUuid = Str::uuid()->toString();

            Step::create([
                'class' => UpdatePositionStatusJob::class,
                'queue' => 'positions',
                'block_uuid' => $uuid,
                'index' => 1,
                'arguments' => [
                    'positionId' => $this->position->id,
                    'status' => 'watching',
                ],
            ]);

            Step::create([
                'class' => ApplyWAPJob::class,
                'queue' => 'positions',
                'block_uuid' => $uuid,
                'child_block_uuid' => $childBlockUuid,
                'index' => 2,
                'arguments' => [
                    'positionId' => $this->position->id,
                ],
            ]);

            Step::create([
                'class' => UpdatePositionStatusJob::class,
                'queue' => 'positions',
                'block_uuid' => $uuid,
                'index' => 3,
                'arguments' => [
                    'positionId' => $this->position->id,
                    'status' => 'active',
                ],
            ]);
        }
    }
}
