<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Models\Account;

use Exception;
use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Throwable;

/*
 * TestAccountServerConnectivityJob
 *
 * • Tests if a server can connect to the exchange API using the account's credentials.
 * • Makes a lightweight signed API call (GetOpenOrders) to verify IP whitelisting.
 * • Used by Account360 "Test Servers" feature to verify all server IPs are whitelisted.
 * • Beautifies IP-related errors for user-friendly display.
 */
final class TestAccountServerConnectivityJob extends BaseApiableJob
{
    // Connectivity tests should fail immediately - no retries
    public int $retries = 1;

    public Account $account;

    public ApiSystem $apiSystem;

    public string $serverHostname;

    public function __construct(int $accountId, string $serverHostname)
    {
        $this->account = Account::findOrFail($accountId);
        $this->apiSystem = $this->account->apiSystem;
        $this->serverHostname = $serverHostname;
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
        // Lightweight signed call - GetOpenOrders
        // Wrap in try-catch to beautify IP-related errors
        try {
            $apiResponse = $this->account->apiQueryOpenOrders();
        } catch (Throwable $e) {
            // Check if this is an IP whitelist error and beautify it
            if ($this->exceptionHandler->isForbidden($e)) {
                throw new Exception("Server IP not whitelisted on {$this->apiSystem->name}. Please add this server's IP to your exchange API whitelist.");
            }

            throw $e;
        }

        return [
            'server' => $this->serverHostname,
            'status' => 'connected',
            'orders_count' => count($apiResponse->result ?? []),
        ];
    }
}
