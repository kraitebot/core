<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Connectivity;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Kraite\Core\Jobs\Lifecycles\Account\TestExchangeConnectivityStep;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\Server;
use Kraite\Core\Support\NotificationService;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Failed;
use StepDispatcher\States\Stopped;

final class AccountServerConnectivityService
{
    /**
     * Start a new connectivity workflow for an existing account.
     *
     * The parent step fans out one child step per API-capable server. The
     * returned server list lets the UI immediately render "testing" rows
     * before the dispatcher has created the child rows.
     *
     * @return array<string, mixed>
     */
    public function start(Account $account): array
    {
        $servers = $this->apiConnectivityServers();

        $step = Step::create([
            'class' => TestExchangeConnectivityStep::class,
            'queue' => 'cronjobs',
            'relatable_type' => Account::class,
            'relatable_id' => $account->id,
            'arguments' => ['accountId' => $account->id],
            'block_uuid' => (string) Str::uuid(),
            'index' => 1,
        ]);

        return [
            'block_uuid' => $step->block_uuid,
            'total_servers' => $servers->count(),
            'servers' => $servers->map(fn (Server $server): array => $this->serverPayload($server, 'testing'))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * The Account a connectivity workflow belongs to, resolved from the
     * parent step's `relatable`. Lets the controller authorize a status
     * lookup (keyed only by block_uuid) against account ownership.
     */
    public function ownerAccount(string $blockUuid): Account
    {
        $account = Step::query()
            ->where('block_uuid', $blockUuid)
            ->where('class', TestExchangeConnectivityStep::class)
            ->where('relatable_type', Account::class)
            ->firstOrFail()
            ->relatable;

        if (! $account instanceof Account) {
            abort(404);
        }

        return $account;
    }

    public function status(string $blockUuid): array
    {
        $parent = Step::query()
            ->where('block_uuid', $blockUuid)
            ->where('class', TestExchangeConnectivityStep::class)
            ->firstOrFail();

        $servers = $this->apiConnectivityServers();
        $children = $parent->child_block_uuid
            ? Step::query()->where('block_uuid', $parent->child_block_uuid)->get()->keyBy(fn (Step $step): int => (int) ($step->arguments['serverId'] ?? 0))
            : collect();

        $rows = $servers->map(function (Server $server) use ($children): array {
            /** @var Step|null $child */
            $child = $children->get($server->id);

            if (! $child) {
                return $this->serverPayload($server, 'testing');
            }

            return $this->serverPayload(
                server: $server,
                status: $this->statusForStep($child),
                step: $child,
            );
        })->values();

        $isComplete = $rows->isEmpty()
            || ($children->count() >= $servers->count()
                && $children->every(fn (Step $step): bool => in_array($step->state::class, Step::terminalStepStates(), true)));

        return [
            'block_uuid' => $blockUuid,
            'is_complete' => $isComplete,
            'total_servers' => $servers->count(),
            'servers' => $rows->all(),
        ];
    }

    public function notify(Account $account, Server $server): bool
    {
        return NotificationService::send(
            user: $account->user,
            canonical: 'server_ip_not_whitelisted',
            referenceData: [
                'type' => 'ip_not_whitelisted',
                'exchange' => $account->apiSystem?->canonical,
                'ip_address' => $server->ip_address,
                'hostname' => $server->hostname,
                'server' => $server->hostname,
                'account_id' => $account->id,
                'account' => $account,
                'account_name' => $account->name,
            ],
            relatable: $account,
            duration: 0,
            cacheKeys: [
                'account_id' => $account->id,
                'ip_address' => $server->ip_address,
            ],
        );
    }

    /**
     * @return Collection<int, Server>
     */
    public function apiConnectivityServers(): Collection
    {
        return Server::query()
            ->where('is_apiable', true)
            ->where('needs_whitelisting', true)
            ->whereNotNull('ip_address')
            ->whereNotIn('type', ['database', 'admin', 'indicators'])
            ->whereNotIn('hostname', ['artemis'])
            ->orderBy('hostname')
            ->get();
    }

    private function statusForStep(Step $step): string
    {
        if ($step->state instanceof Completed) {
            return 'connected';
        }

        if ($step->state instanceof Failed || $step->state instanceof Stopped) {
            return 'not_connected';
        }

        return 'testing';
    }

    /**
     * @return array<string, mixed>
     */
    private function serverPayload(Server $server, string $status, ?Step $step = null): array
    {
        return [
            'id' => $server->id,
            'hostname' => $server->hostname,
            'ip_address' => $server->ip_address,
            'queue' => $server->own_queue_name ?: 'default',
            'status' => $status,
            'step_id' => $step?->id,
            'error_message' => $step?->error_message,
            'response' => $step?->response,
            'can_notify_user' => $status === 'not_connected',
        ];
    }
}
