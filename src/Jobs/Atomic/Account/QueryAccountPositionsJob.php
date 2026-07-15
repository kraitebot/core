<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Account;

use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSnapshot;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Support\PositionSnapshot;
use UnexpectedValueException;

/**
 * QueryAccountPositionsJob (Atomic)
 *
 * Queries the exchange for currently open positions on a specific account.
 * Stores the API result in the `api_snapshots` table for subsequent jobs.
 */
class QueryAccountPositionsJob extends BaseApiableJob
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
        $apiResponse = $this->account->apiQueryPositions();

        if (! PositionSnapshot::fromApiResponse($this->account, $apiResponse)->isValid()) {
            throw new UnexpectedValueException(sprintf(
                'Invalid %s positions response for account %d; trusted snapshot preserved.',
                $this->apiSystem->canonical,
                $this->account->id,
            ));
        }

        ApiSnapshot::storeFor($this->account, 'account-positions', $apiResponse->result);

        return [
            'account_id' => $this->account->id,
            'positions_count' => is_array($apiResponse->result) ? count($apiResponse->result) : 0,
            'positions' => $apiResponse->result,
        ];
    }
}
