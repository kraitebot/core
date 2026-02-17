<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\Account;

use Kraite\Core\Abstracts\BaseAccountLifecycle;
use Kraite\Core\Jobs\Atomic\Account\QueryAccountPositionsJob as AtomicQueryAccountPositionsJob;
use StepDispatcher\Models\Step;

/**
 * QueryAccountPositionsJob (Lifecycle)
 *
 * Orchestrator that creates step(s) for querying account positions.
 * Default implementation creates a single atomic step.
 * Exchange-specific overrides can add additional steps if needed
 * (e.g., querying margin info, leverage settings, etc.).
 */
class QueryAccountPositionsJob extends BaseAccountLifecycle
{
    public function dispatch(string $blockUuid, int $startIndex, ?string $workflowId = null): int
    {
        Step::create([
            'class' => $this->resolver->resolve(AtomicQueryAccountPositionsJob::class),
            'arguments' => ['accountId' => $this->account->id],
            'block_uuid' => $blockUuid,
            'index' => $startIndex,
            'workflow_id' => $workflowId,
        ]);

        return $startIndex + 1;
    }
}
