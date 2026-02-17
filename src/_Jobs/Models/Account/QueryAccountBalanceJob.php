<?php

declare(strict_types=1);

namespace Kraite\Core\_Jobs\Models\Account;

use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSnapshot;
use Kraite\Core\Models\ApiSystem;

/*
 * QueryAccountBalanceJob
 *
 * • Queries the exchange for account balance.
 * • Stores the API result in the `api_snapshots` table for subsequent jobs.
 * • Uses the API system's canonical name to assign proper limiter and handler.
 */
final class QueryAccountBalanceJob extends BaseApiableJob
{
    public Account $account;

    public ApiSystem $apiSystem;

    public function __construct(int $accountId)
    {
        $this->account = Account::findOrFail($accountId);
        $this->apiSystem = $this->account->apiSystem;
    }

    public function assignExceptionHandler(): void
    {
        $canonical = $this->apiSystem->canonical;
        $this->exceptionHandler = BaseExceptionHandler::make($canonical)
            ->withAccount($this->account);
    }

    public function relatable()
    {
        return $this->account;
    }

    public function computeApiable()
    {
        $apiResponse = $this->account->apiQueryBalance();

        ApiSnapshot::storeFor($this->account, 'account-balance', $apiResponse->result);

        return [
            'account_id' => $this->account->id,
            'balance' => $apiResponse->result,
        ];
    }
}
