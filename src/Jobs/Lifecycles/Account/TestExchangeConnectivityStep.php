<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\Account;

use Illuminate\Support\Str;
use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Jobs\Atomic\Account\TestServerConnectivityStep;
use Kraite\Core\Models\Account;
use Kraite\Core\Support\Connectivity\AccountServerConnectivityService;
use StepDispatcher\Models\Step;

final class TestExchangeConnectivityStep extends BaseQueueableJob
{
    public function __construct(public int $accountId) {}

    /** @return array<string, int|string> */
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

        $workflowId = (string) Str::uuid();
        $dispatched = 0;

        $built = $this->buildChildChainOnce(function (string $childBlockUuid) use ($account, $servers, $workflowId, &$dispatched): void {
            foreach ($servers as $server) {
                Step::create([
                    'class' => TestServerConnectivityStep::class,
                    // Same latency contract as the parent: user-facing
                    // probes ride the dispatcher's uncapped high-priority
                    // pass, never the 100-row FIFO window.
                    'priority' => 'high',
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
            }
        });

        if (! $built) {
            $dispatched = Step::query()
                ->where('block_uuid', $this->step->child_block_uuid)
                ->forClasses(TestServerConnectivityStep::class)
                ->count();
        }

        return [
            'account_id' => $account->id,
            'servers_dispatched' => $dispatched,
            'servers_failed' => 0,
        ];
    }
}
