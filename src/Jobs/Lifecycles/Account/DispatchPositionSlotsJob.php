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

        // Both classes that can carry the orchestrator — base or per-exchange
        // override. Either one in a non-terminal state means the position is
        // already being processed by an active workflow.
        $candidateClasses = array_unique([DispatchPositionJob::class, $dispatchJobClass]);
        $terminalStates = Step::terminalStepStates();

        $dispatched = 0;
        $skipped = 0;

        // Step 1: Dispatch each position with ISOLATED block_uuids
        // Each position is fully independent - one failure doesn't cascade to others.
        // Account-level issues are caught earlier (VerifyMinAccountBalanceJob, etc.)
        //
        // Idempotency guard: skip positions that already carry a live (non-
        // terminal) DispatchPositionJob step. Two concurrent
        // PreparePositionsOpening blocks for the same account (operator
        // manual cron racing the scheduled tick, recover-stale recovery
        // re-running this orchestrator, etc.) used to produce one
        // DispatchPositionJob per position per instance — twin workflows
        // racing the exchange, with the loser hitting the LIMIT-cap guard
        // mid-ladder and triggering CancelPositionJob at a realised loss.
        // The 2026-04-25 17:33 cluster of 12 Failed steps (positions
        // #241 + #242) was exactly this. Same shape as the orphan-
        // recovery dedup in CreatePositionsCommand.
        foreach ($positions as $position) {
            $hasLiveStep = Step::query()
                ->whereIn('class', $candidateClasses)
                ->whereJsonContains('arguments->positionId', $position->id)
                ->whereNotIn('state', $terminalStates)
                ->exists();

            if ($hasLiveStep) {
                $skipped++;

                continue;
            }

            Step::create([
                'class' => $dispatchJobClass,
                'queue' => 'positions',
                'arguments' => ['positionId' => $position->id],
                'block_uuid' => (string) Str::uuid(),
                'workflow_id' => $workflowId,
                'index' => 1,
            ]);

            $dispatched++;
        }

        return [
            'account_id' => $this->account->id,
            'positions_dispatched' => $dispatched,
            'positions_skipped_already_live' => $skipped,
            'message' => 'Position dispatching initiated',
        ];
    }
}
