<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Account;

use Exception;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request as Psr7Request;
use Illuminate\Http\Client\RequestException as HttpClientRequestException;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\Server;
use Kraite\Core\Support\Security\ExchangeApiKeyPermissions;
use Throwable;

final class TestServerConnectivityStep extends BaseQueueableJob
{
    public int $retries = 1;

    public Account $account;

    public Server $server;

    public function __construct(int $accountId, int $serverId)
    {
        $this->account = Account::findOrFail($accountId);
        $this->server = Server::findOrFail($serverId);
    }

    public function relatable(): Account
    {
        return $this->account;
    }

    protected function compute(): array
    {
        $handler = BaseExceptionHandler::make($this->account->apiSystem->canonical)
            ->withAccount($this->account);

        try {
            $balance = $this->account->apiQueryBalance();
            $openOrders = $this->account->apiQueryOpenOrders();
            $withdrawalsEnabled = null;

            if (ExchangeApiKeyPermissions::supports($this->account->apiSystem->canonical)) {
                $withdrawalsEnabled = $this->account->apiQueryWithdrawalPermission()->result['withdrawals_enabled'];
            }
        } catch (Throwable $e) {
            $this->recordForbiddenHostnameIfNeeded($handler, $e);

            throw $this->translateUserFixableFailure($handler, $e);
        }

        return [
            'account_id' => $this->account->id,
            'server_id' => $this->server->id,
            'server_hostname' => $this->server->hostname,
            'server_ip' => $this->server->ip_address,
            'result' => 'ok',
            'balance_checked' => $balance->result !== null,
            'open_orders_count' => count($openOrders->result ?? []),
            'withdrawals_enabled' => $withdrawalsEnabled,
        ];
    }

    /**
     * The three failures a registrant can fix on their own get a plain
     * message (the raw exchange error is kept as `previous`). The wizard
     * matches these phrases to show a targeted alert; anything else keeps
     * the original exception.
     */
    private function translateUserFixableFailure(BaseExceptionHandler $handler, Throwable $exception): Throwable
    {
        $exchange = $this->account->apiSystem->name;
        $classifiable = $this->classifiableException($exception);

        if ($handler->isMissingPermissions($classifiable)) {
            // Only Bitget classifies this today (error 40014 on a unified
            // key), and the scope it refuses without is always the
            // management read — name it so the user knows exactly what
            // to tick.
            return new Exception(
                "The API key is missing a required permission on {$exchange}: enable \"Unified account management (read-only)\" on the key, then run the test again.",
                previous: $exception,
            );
        }

        // Binance answers key, permission, and whitelist failures with the
        // same -2015 message, so its rejection cannot be split further —
        // surface every possible cause at once.
        if ($this->account->apiSystem->canonical === 'binance'
            && ($handler->isIpNotWhitelisted($classifiable) || $handler->isAccountBlocked($classifiable))) {
            return new Exception(
                "{$exchange} rejected this API key — it is invalid, missing permissions, or a server IP is not whitelisted. Fix the key (every permission and every IP listed), then run the test again.",
                previous: $exception,
            );
        }

        if ($handler->isIpNotWhitelisted($classifiable)) {
            return new Exception(
                "This server's IP is not whitelisted on {$exchange}. Add every listed IP address to the API key, then run the test again.",
                previous: $exception,
            );
        }

        if ($handler->isAccountBlocked($classifiable)) {
            return new Exception(
                "{$exchange} rejected this API key — it is invalid, disabled, or missing permissions. Recreate the key with every permission listed, then run the test again.",
                previous: $exception,
            );
        }

        return $exception;
    }

    /**
     * The exception classifiers only understand Guzzle's RequestException
     * (production transport). The testing transport surfaces failures as
     * the Laravel HTTP client's RequestException — rewrap it so both
     * transports classify identically.
     */
    private function classifiableException(Throwable $exception): Throwable
    {
        if ($exception instanceof HttpClientRequestException) {
            return new RequestException(
                $exception->getMessage(),
                new Psr7Request('GET', 'connectivity-probe'),
                $exception->response->toPsrResponse(),
                $exception,
            );
        }

        return $exception;
    }

    private function recordForbiddenHostnameIfNeeded(BaseExceptionHandler $handler, Throwable $exception): void
    {
        if (! $exception instanceof RequestException) {
            return;
        }

        if ($handler->isIpNotWhitelisted($exception)) {
            $handler->forbidIpNotWhitelisted($exception);

            return;
        }

        if ($handler->isIpRateLimited($exception)) {
            $handler->forbidIpRateLimited($exception);

            return;
        }

        if ($handler->isIpBanned($exception)) {
            $handler->forbidIpBanned($exception);

            return;
        }

        if ($handler->isAccountBlocked($exception)) {
            $handler->forbidAccountBlocked($exception);
        }
    }
}
