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
        $workflowId = (string) Str::uuid();

        foreach ($accounts as $account) {
            Step::create([
                'class' => StoreAccountBalanceJob::class,
                'arguments' => ['accountId' => $account->id],
                'block_uuid' => $this->uuid(),
                'workflow_id' => $workflowId,
                'index' => 1,
            ]);
        }

        return [
            'accounts_dispatched' => $accounts->count(),
        ];
    }
}
