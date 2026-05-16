<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Account;

use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\Server;
use Kraite\Core\Support\Connectivity\AccountServerConnectivityService;

final class SendServerNotWhitelistedNotificationStep extends BaseQueueableJob
{
    public function __construct(public int $accountId, public int $serverId) {}

    protected function compute(): array
    {
        $account = Account::findOrFail($this->accountId);
        $server = Server::findOrFail($this->serverId);
        $sent = app(AccountServerConnectivityService::class)->notify($account, $server);

        return [
            'account_id' => $account->id,
            'server_id' => $server->id,
            'server_hostname' => $server->hostname,
            'server_ip' => $server->ip_address,
            'sent' => $sent,
        ];
    }
}
