<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\Account;

use Kraite\Core\Abstracts\BaseAccountLifecycle;
use Kraite\Core\Jobs\Atomic\Account\QueryAccountOpenOrdersJob as AtomicQueryAccountOpenOrdersJob;
use StepDispatcher\Models\Step;

/**
 * QueryAccountOpenOrdersJob (Lifecycle)
 *
 * Orchestrator that creates step(s) for querying account open orders.
 * Default implementation creates a single atomic step.
 * Exchange-specific overrides can add additional steps if needed
 * (e.g., querying conditional orders, stop orders, etc.).
 */
class QueryAccountOpenOrdersJob extends BaseAccountLifecycle
{
    public function dispatch(string $blockUuid, int $startIndex, ?string $workflowId = null): int
    {
        Step::create([
            'class' => $this->resolver->resolve(AtomicQueryAccountOpenOrdersJob::class),
            'arguments' => ['accountId' => $this->account->id],
            'block_uuid' => $blockUuid,
            'index' => $startIndex,
            'workflow_id' => $workflowId,
        ]);

        return $startIndex + 1;
    }
}
