<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\Account;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Jobs\Lifecycles\Position\DispatchPositionJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Proxies\JobProxy;
use StepDispatcher\Models\Step;
use Throwable;

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
        // Re-fetch account + user state at the final dispatch boundary.
        // PreparePositionsOpening's earlier child steps captured the
        // Account model when the orchestrator's compute() began. If an
        // operator disabled trading mid-workflow, the already-created
        // `new` positions would otherwise be dispatched against an
        // account that's no longer eligible. Refusing to dispatch here
        // keeps the slots in `new` so a subsequent re-enable can pick
        // them up cleanly, OR a scrub job can clean them — without
        // ever placing exchange orders against the disabled account.
        $freshAccount = $this->account->fresh(['apiSystem', 'user']);

        if (! $freshAccount || ! $freshAccount->isReadyToTrade()) {
            return [
                'account_id' => $this->account->id,
                'positions_dispatched' => 0,
                'message' => 'Account is no longer ready to open positions — refusing to dispatch slots',
            ];
        }

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
        $failed = 0;

        foreach ($positions as $position) {
            // Per-position try/catch — at peak slot rotation a single
            // position's Step::create can throw (DB transient, observer
            // chain blowing up on a malformed exchange_symbol relation,
            // etc.). One bad row must not abort the rest of the slot
            // dispatch — the account would silently lose every position
            // after the failing index in the iteration order.
            try {
                if (Step::hasLiveWorkflow($position, $candidateClasses)) {
                    $skipped++;

                    continue;
                }

                Step::create([
                    'class' => $dispatchJobClass,
                    'queue' => 'positions',
                    'relatable_type' => Position::class,
                    'relatable_id' => $position->id,
                    'arguments' => ['positionId' => $position->id],
                    'block_uuid' => (string) Str::uuid(),
                    'workflow_id' => $workflowId,
                    'index' => 1,
                ]);

                $dispatched++;
            } catch (Throwable $e) {
                $failed++;
                Log::channel('jobs')->error('[DISPATCH-SLOTS] per-position dispatch threw — continuing', [
                    'account_id' => $this->account->id,
                    'position_id' => $position->id,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return [
            'account_id' => $this->account->id,
            'positions_dispatched' => $dispatched,
            'positions_skipped_already_live' => $skipped,
            'positions_failed' => $failed,
            'message' => 'Position dispatching initiated',
        ];
    }
}
