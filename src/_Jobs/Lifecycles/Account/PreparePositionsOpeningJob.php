<?php

declare(strict_types=1);

namespace Kraite\Core\_Jobs\Lifecycles\Account;

use Illuminate\Support\Str;
use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\_Jobs\Models\Account\AssignBestTokensToPositionSlotsJob;
use Kraite\Core\_Jobs\Models\Account\QueryAccountOpenOrdersJob;
use Kraite\Core\_Jobs\Models\Account\QueryAccountPositionsJob;
use Kraite\Core\_Jobs\Models\Account\VerifyMinAccountBalanceJob;
use Kraite\Core\Models\Account;
use StepDispatcher\Models\Step;

/*
 * PreparePositionsOpeningJob
 *
 * Prepares and validates position opening for an account:
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
        // Step 1: Query balance + verify minimum (showstopper)
        Step::create([
            'class' => VerifyMinAccountBalanceJob::class,
            'arguments' => [
                'accountId' => $this->account->id,
            ],
            'block_uuid' => $this->uuid(),
            'index' => 1,
        ]);

        // Step 2: Query exchange for open positions (parallel)
        Step::create([
            'class' => QueryAccountPositionsJob::class,
            'arguments' => [
                'accountId' => $this->account->id,
            ],
            'block_uuid' => $this->uuid(),
            'index' => 2,
        ]);

        // Step 2: Query exchange for open orders (parallel)
        Step::create([
            'class' => QueryAccountOpenOrdersJob::class,
            'arguments' => [
                'accountId' => $this->account->id,
            ],
            'block_uuid' => $this->uuid(),
            'index' => 2,
        ]);

        // Step 3: Create slots + assign best tokens (deletes unassigned slots)
        Step::create([
            'class' => AssignBestTokensToPositionSlotsJob::class,
            'arguments' => [
                'accountId' => $this->account->id,
            ],
            'block_uuid' => $this->uuid(),
            'index' => 3,
        ]);

        // Step 4: Dispatch positions for trading
        Step::create([
            'class' => DispatchPositionSlotsJob::class,
            'arguments' => [
                'accountId' => $this->account->id,
            ],
            'block_uuid' => $this->uuid(),
            'child_block_uuid' => (string) Str::uuid(),
            'index' => 4,
        ]);

        return [
            'account_id' => $this->account->id,
            'message' => 'Position opening preparation initiated',
        ];
    }
}
