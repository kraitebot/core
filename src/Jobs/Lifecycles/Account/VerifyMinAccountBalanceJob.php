<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\Account;

use Kraite\Core\Abstracts\BaseAccountLifecycle;
use Kraite\Core\Jobs\Atomic\Account\VerifyMinAccountBalanceJob as AtomicVerifyMinAccountBalanceJob;
use StepDispatcher\Models\Step;

/**
 * VerifyMinAccountBalanceJob (Lifecycle)
 *
 * Orchestrator that creates step(s) for verifying minimum account balance.
 * Default implementation creates a single atomic step.
 * Exchange-specific overrides can add additional steps if needed.
 */
final class VerifyMinAccountBalanceJob extends BaseAccountLifecycle
{
    public function dispatch(string $blockUuid, int $startIndex, ?string $workflowId = null): int
    {
        Step::create([
            'class' => $this->resolver->resolve(AtomicVerifyMinAccountBalanceJob::class),
            'arguments' => ['accountId' => $this->account->id],
            'block_uuid' => $blockUuid,
            'index' => $startIndex,
            'workflow_id' => $workflowId,
        ]);

        return $startIndex + 1;
    }
}
