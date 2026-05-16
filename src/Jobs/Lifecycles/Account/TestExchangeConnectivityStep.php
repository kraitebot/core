<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\Account;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Jobs\Atomic\Account\TestServerConnectivityStep;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\Server;
use Kraite\Core\Support\Connectivity\AccountServerConnectivityService;
use StepDispatcher\Models\Step;
use Throwable;

final class TestExchangeConnectivityStep extends BaseQueueableJob
{
    public function __construct(public int $accountId) {}

    protected function compute(): array
    {
        $account = Account::findOrFail($this->accountId);
        $servers = app(AccountServerConnectivityService::class)->apiConnectivityServers();

        if ($servers->isEmpty()) {
            return [
                'account_id' => $account->id,
                'servers_dispatched' => 0,
                'message' => 'No API connectivity servers configured.',
            ];
        }

        $childBlockUuid = $this->step->child_block_uuid ?? $this->step->makeItAParent();
        $workflowId = (string) Str::uuid();
        $dispatched = 0;
        $failed = 0;

        foreach ($servers as $server) {
            try {
                Step::create([
                    'class' => TestServerConnectivityStep::class,
                    'queue' => $server->own_queue_name ?: 'default',
                    'relatable_type' => Account::class,
                    'relatable_id' => $account->id,
                    'arguments' => [
                        'accountId' => $account->id,
                        'serverId' => $server->id,
                    ],
                    'block_uuid' => $childBlockUuid,
                    'workflow_id' => $workflowId,
                    'index' => 1,
                ]);

                $dispatched++;
            } catch (Throwable $e) {
                $failed++;

                Log::channel('jobs')->error('[CONNECTIVITY] Failed to create server child step', [
                    'account_id' => $account->id,
                    'server_id' => $server instanceof Server ? $server->id : null,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return [
            'account_id' => $account->id,
            'servers_dispatched' => $dispatched,
            'servers_failed' => $failed,
        ];
    }
}
