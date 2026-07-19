<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\Position\Bitget;

use Kraite\Core\Abstracts\BasePositionLifecycle;
use Kraite\Core\Jobs\Atomic\Position\Bitget\SyncPositionModeJob as AtomicSyncPositionModeJob;
use StepDispatcher\Models\Step;

final class SyncPositionModeJob extends BasePositionLifecycle
{
    public function dispatch(string $blockUuid, int $startIndex, ?string $workflowId = null): int
    {
        Step::create([
            'class' => $this->resolver->resolve(AtomicSyncPositionModeJob::class),
            'queue' => 'positions',
            'arguments' => ['positionId' => $this->position->id],
            'block_uuid' => $blockUuid,
            'index' => $startIndex,
            'workflow_id' => $workflowId,
        ]);

        return $startIndex + 1;
    }
}
