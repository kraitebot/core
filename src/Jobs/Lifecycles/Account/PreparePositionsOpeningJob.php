<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\Account;

use Illuminate\Support\Str;
use Kraite\Core\Abstracts\BaseQueueableJob;
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
 * • Step 3: AssignBestTokensToPositionSlotsJob - Creates slots + assigns optimal tokens
 * • Step 4: DispatchPositionSlotsJob - Dispatches positions for trading
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
        // Idempotency guard — if a prior invocation already wrote children
        // into this block, this is a retry (typically triggered by
        // steps:recover-stale flipping the parent Running → Pending after
        // the stale threshold, because the framework cannot distinguish a
        // parent legitimately waiting for its child tree from an orphaned
        // Running step). Without this guard, every retry would create
        // another full round of Verify/Query/Assign/Dispatch steps, and
        // AssignBestTokensToPositionSlotsJob would spawn duplicate Position
        // rows on every round.
        $alreadyDispatched = Step::query()
            ->where('block_uuid', $this->uuid())
            ->exists();

        if ($alreadyDispatched) {
            return [
                'account_id' => $this->account->id,
                'message' => 'Retry detected — child block already populated, no-op.',
            ];
        }

        $resolver = JobProxy::with($this->account);
        $workflowId = (string) Str::uuid();

        // Step 1: Query balance + verify minimum (showstopper)
        // Uses Lifecycle pattern for exchange-specific behavior
        $lifecycleClass = $resolver->resolve(VerifyMinAccountBalanceLifecycle::class);
        $lifecycle = new $lifecycleClass($this->account);
        $nextIndex = $lifecycle->dispatch(
            blockUuid: $this->uuid(),
            startIndex: 1,
            workflowId: $workflowId
        );

        // Step 2: Query exchange for open positions (parallel)
        $positionsLifecycleClass = $resolver->resolve(QueryAccountPositionsLifecycle::class);
        $positionsLifecycle = new $positionsLifecycleClass($this->account);
        $positionsLifecycle->dispatch(
            blockUuid: $this->uuid(),
            startIndex: $nextIndex,
            workflowId: $workflowId
        );

        // Step 2: Query exchange for open orders (parallel - same index)
        $ordersLifecycleClass = $resolver->resolve(QueryAccountOpenOrdersLifecycle::class);
        $ordersLifecycle = new $ordersLifecycleClass($this->account);
        $nextIndex = $ordersLifecycle->dispatch(
            blockUuid: $this->uuid(),
            startIndex: $nextIndex,
            workflowId: $workflowId
        );

        // Step 3: Create slots + assign best tokens (no resolver needed - same for all exchanges)
        Step::create([
            'class' => AssignBestTokensToPositionSlotsJob::class,
            'queue' => 'cronjobs',
            'arguments' => ['accountId' => $this->account->id],
            'block_uuid' => $this->uuid(),
            'workflow_id' => $workflowId,
            'index' => $nextIndex,
        ]);

        $nextIndex++;

        // Step 4: Dispatch positions for trading
        Step::create([
            'class' => DispatchPositionSlotsJob::class,
            'queue' => 'cronjobs',
            'arguments' => ['accountId' => $this->account->id],
            'block_uuid' => $this->uuid(),
            'child_block_uuid' => (string) Str::uuid(),
            'workflow_id' => $workflowId,
            'index' => $nextIndex,
        ]);

        return [
            'account_id' => $this->account->id,
            'message' => 'Position opening preparation initiated',
        ];
    }
}
