<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\Account;

use Illuminate\Support\Str;
use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Jobs\Atomic\Account\StoreAccountBalanceJob;
use Kraite\Core\Models\Account;
use StepDispatcher\Models\Step;

/**
 * DispatchAccountBalancesJob (Lifecycle)
 *
 * Orchestrator that creates parallel StoreAccountBalanceJob steps
 * for each active account. All steps run at the same index (parallel).
 */
final class DispatchAccountBalancesJob extends BaseQueueableJob
{
    public function compute(): array
    {
        $accounts = Account::active()->get();

        // No active accounts → no children to spawn → don't elect to parent.
        // Step Completes as an orphan, no zombie.
        if ($accounts->isEmpty()) {
            return [
                'accounts_dispatched' => 0,
                'message' => 'No active accounts — nothing to dispatch.',
            ];
        }

        $childBlockUuid = $this->step->child_block_uuid ?? $this->step->makeItAParent();
        $workflowId = (string) Str::uuid();

        foreach ($accounts as $account) {
            Step::create([
                'class' => StoreAccountBalanceJob::class,
                'queue' => 'cronjobs',
                'arguments' => ['accountId' => $account->id],
                'block_uuid' => $childBlockUuid,
                'workflow_id' => $workflowId,
                'index' => 1,
            ]);
        }

        return [
            'accounts_dispatched' => $accounts->count(),
        ];
    }
}
