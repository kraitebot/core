<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\Account;

use Illuminate\Support\Str;
use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Jobs\Atomic\Account\SyncAccountActivityFlagsJob;
use Kraite\Core\Jobs\Lifecycles\Account\QueryAccountOpenOrdersJob as QueryAccountOpenOrdersLifecycle;
use Kraite\Core\Jobs\Lifecycles\Account\QueryAccountPositionsJob as QueryAccountPositionsLifecycle;
use Kraite\Core\Jobs\Lifecycles\Account\VerifyMinAccountBalanceJob as VerifyMinAccountBalanceLifecycle;
use Kraite\Core\Jobs\Models\Account\AssignBestTokensToPositionSlotsJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Support\Proxies\JobProxy;
use StepDispatcher\Models\Step;

/**
 * PreparePositionsOpeningJob
 *
 * Prepares and validates position opening for an account.
 * This is an orchestrator step (NOT proxied - same logic for all exchanges).
 * Uses Lifecycle classes internally to dispatch exchange-specific atomic jobs.
 *
 * Flow:
 * • Step 1: VerifyMinAccountBalanceJob - Queries balance + verifies minimum (showstopper)
 * • Step 2: QueryAccountPositionsJob - Fetches open positions from exchange (parallel)
 * • Step 2: QueryAccountOpenOrdersJob - Fetches open orders from exchange (parallel)
 * • Step 3: SyncAccountActivityFlagsJob - Syncs allow_other_* protection flags from the fresh snapshots
 * • Step 4: AssignBestTokensToPositionSlotsJob - Creates slots + assigns optimal tokens
 * • Step 5: DispatchPositionSlotsJob - Dispatches positions for trading
 */
final class PreparePositionsOpeningJob extends BaseQueueableJob
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
        $resolver = JobProxy::with($this->account);
        $workflowId = (string) Str::uuid();

        $built = $this->buildChildChainOnce(function (string $childBlockUuid) use ($resolver, $workflowId): void {
            $lifecycleClass = $resolver->resolve(VerifyMinAccountBalanceLifecycle::class);
            $lifecycle = new $lifecycleClass($this->account);
            $nextIndex = $lifecycle->dispatch(
                blockUuid: $childBlockUuid,
                startIndex: 1,
                workflowId: $workflowId
            );

            $positionsLifecycleClass = $resolver->resolve(QueryAccountPositionsLifecycle::class);
            $positionsLifecycle = new $positionsLifecycleClass($this->account);
            $positionsLifecycle->dispatch(
                blockUuid: $childBlockUuid,
                startIndex: $nextIndex,
                workflowId: $workflowId
            );

            $ordersLifecycleClass = $resolver->resolve(QueryAccountOpenOrdersLifecycle::class);
            $ordersLifecycle = new $ordersLifecycleClass($this->account);
            $nextIndex = $ordersLifecycle->dispatch(
                blockUuid: $childBlockUuid,
                startIndex: $nextIndex,
                workflowId: $workflowId
            );

            // Re-check for user activity on the freshly-written snapshots
            // BEFORE slot assignment and sizing — a user who started
            // trading manually since registration must flip the account
            // to protected/available-balance mode within this same run.
            Step::create([
                'class' => SyncAccountActivityFlagsJob::class,
                'queue' => 'cronjobs',
                'arguments' => ['accountId' => $this->account->id],
                'block_uuid' => $childBlockUuid,
                'workflow_id' => $workflowId,
                'index' => $nextIndex,
            ]);

            $nextIndex++;

            Step::create([
                'class' => AssignBestTokensToPositionSlotsJob::class,
                'queue' => 'cronjobs',
                'arguments' => ['accountId' => $this->account->id],
                'block_uuid' => $childBlockUuid,
                'workflow_id' => $workflowId,
                'index' => $nextIndex,
            ]);

            $nextIndex++;

            Step::create([
                'class' => DispatchPositionSlotsJob::class,
                'queue' => 'cronjobs',
                'arguments' => ['accountId' => $this->account->id],
                'block_uuid' => $childBlockUuid,
                'workflow_id' => $workflowId,
                'index' => $nextIndex,
            ]);
        });

        return [
            'account_id' => $this->account->id,
            'message' => $built
                ? 'Position opening preparation initiated'
                : 'Retry detected — child block already populated, no-op.',
        ];
    }
}
