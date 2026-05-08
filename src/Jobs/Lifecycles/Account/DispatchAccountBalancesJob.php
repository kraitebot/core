<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\Account;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Jobs\Atomic\Account\StoreAccountBalanceJob;
use Kraite\Core\Models\Account;
use StepDispatcher\Models\Step;
use Throwable;

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

        $dispatched = 0;
        $failed = 0;

        foreach ($accounts as $account) {
            // Per-account try/catch — at 200+ accounts, one transient
            // Step::create failure must not abort the rest of the
            // fan-out. Each child step is independent; logging the
            // failure and continuing keeps the balance pipeline
            // tracking 199 healthy accounts even if one row's
            // dispatch chokes.
            try {
                Step::create([
                    'class' => StoreAccountBalanceJob::class,
                    'queue' => 'cronjobs',
                    'relatable_type' => Account::class,
                    'relatable_id' => $account->id,
                    'arguments' => ['accountId' => $account->id],
                    'block_uuid' => $childBlockUuid,
                    'workflow_id' => $workflowId,
                    'index' => 1,
                ]);
                $dispatched++;
            } catch (Throwable $e) {
                $failed++;
                Log::channel('jobs')->error('[DISPATCH-BALANCES] per-account dispatch threw — continuing', [
                    'account_id' => $account->id,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return [
            'accounts_dispatched' => $dispatched,
            'accounts_failed' => $failed,
        ];
    }
}
