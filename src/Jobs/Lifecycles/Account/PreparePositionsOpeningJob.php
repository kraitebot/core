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
use StepDispatcher\Models\Step;
use Kraite\Core\Support\Proxies\JobProxy;

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
            'arguments' => ['accountId' => $this->account->id],
            'block_uuid' => $this->uuid(),
            'workflow_id' => $workflowId,
            'index' => $nextIndex,
        ]);

        $nextIndex++;

        // Step 4: Dispatch positions for trading
        Step::create([
            'class' => DispatchPositionSlotsJob::class,
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
