<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\Account;

use Illuminate\Support\Str;
use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Jobs\Lifecycles\Position\DispatchPositionJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Support\Proxies\JobProxy;
use StepDispatcher\Models\Step;

/**
 * DispatchPositionSlotsJob
 *
 * Dispatches all new positions for an account that have tokens assigned.
 * This is an orchestrator step (NOT proxied - same logic for all exchanges).
 * Uses JobProxy to resolve exchange-specific DispatchPositionJob lifecycle.
 *
 * Flow:
 * • Step 1: DispatchPositionJob (parallel) - Exchange-specific lifecycle for each position
 */
final class DispatchPositionSlotsJob extends BaseQueueableJob
{
    public Account $account;

    public function __construct(int $accountId)
    {
        $this->account = Account::findOrFail($accountId);
    }

    public function relatable()
    {
        return $this->account;
    }

    public function compute()
    {
        // Get all new positions for this account that have a token assigned
        $positions = $this->account->positions()
            ->where('status', 'new')
            ->whereNotNull('exchange_symbol_id')
            ->get();

        if ($positions->isEmpty()) {
            return [
                'account_id' => $this->account->id,
                'positions_dispatched' => 0,
                'message' => 'No new positions to dispatch',
            ];
        }

        $workflowId = (string) Str::uuid();
        $resolver = JobProxy::with($this->account);
        $dispatchJobClass = $resolver->resolve(DispatchPositionJob::class);

        // Step 1: Dispatch each position with ISOLATED block_uuids
        // Each position is fully independent - one failure doesn't cascade to others.
        // Account-level issues are caught earlier (VerifyMinAccountBalanceJob, etc.)
        foreach ($positions as $position) {
            Step::create([
                'class' => $dispatchJobClass,
                'queue' => 'positions',
                'arguments' => ['positionId' => $position->id],
                'block_uuid' => (string) Str::uuid(),
                'workflow_id' => $workflowId,
                'index' => 1,
            ]);
        }

        return [
            'account_id' => $this->account->id,
            'positions_dispatched' => $positions->count(),
            'message' => 'Position dispatching initiated',
        ];
    }
}
