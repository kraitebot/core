<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Account;

use GuzzleHttp\Exception\RequestException;
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

            throw $e;
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
