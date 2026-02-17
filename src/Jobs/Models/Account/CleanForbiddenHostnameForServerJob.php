<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Models\Account;

use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ForbiddenHostname;

/*
 * CleanForbiddenHostnameForServerJob
 *
 * • Cleans/deletes ForbiddenHostname records for a specific account + server IP.
 * • Used as index=1 in the server connectivity test lifecycle.
 * • This allows the subsequent TestAccountServerConnectivityJob (index=2) to
 *   retry the connection without being blocked by stale ForbiddenHostname entries.
 * • Only cleans account-specific entries, not system-wide IP bans.
 */
final class CleanForbiddenHostnameForServerJob extends BaseQueueableJob
{
    public Account $account;

    public ApiSystem $apiSystem;

    public string $serverIp;

    public string $serverHostname;

    public function __construct(int $accountId, string $serverIp, string $serverHostname)
    {
        $this->account = Account::findOrFail($accountId);
        $this->apiSystem = $this->account->apiSystem;
        $this->serverIp = $serverIp;
        $this->serverHostname = $serverHostname;
    }

    public function relatable()
    {
        return $this->account;
    }

    protected function compute()
    {
        // Delete account-specific ForbiddenHostname records for this IP + API system
        // We only delete records where account_id matches (not system-wide bans)
        $deleted = ForbiddenHostname::query()
            ->where('account_id', $this->account->id)
            ->where('api_system_id', $this->apiSystem->id)
            ->where('ip_address', $this->serverIp)
            ->delete();

        return [
            'account_id' => $this->account->id,
            'api_system' => $this->apiSystem->canonical,
            'server_ip' => $this->serverIp,
            'server_hostname' => $this->serverHostname,
            'deleted_count' => $deleted,
        ];
    }
}
