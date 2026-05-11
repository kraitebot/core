<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Cronjobs;

use Kraite\Core\Jobs\Atomic\Position\FetchAccountPositionsPnlJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Proxies\JobProxy;
use StepDispatcher\Models\Step;
use StepDispatcher\Support\BaseCommand;

/**
 * UpsertPnlsCommand
 *
 * Cyclic cron that backfills exchange-reported PnL for closed positions
 * that don't have it yet. Fans out one atomic step per account.
 * Exchange-specific implementations resolve via JobProxy.
 */
final class UpsertPnlsCommand extends BaseCommand
{
    protected $signature = 'kraite:cron-upsert-pnls
                            {--output : Display command output (silent by default)}';

    protected $description = 'Fetches and stores exchange-reported PnL for closed positions.';

    public function handle(): int
    {
        $accountIds = Position::where('status', 'closed')
            ->whereNull('pnl')
            ->whereNotNull('closed_at')
            ->where('closed_at', '>', now()->subMonths(3))
            ->distinct()
            ->pluck('account_id');

        if ($accountIds->isEmpty()) {
            $this->verboseInfo('No positions pending PnL fetch.');

            return self::SUCCESS;
        }

        $accounts = Account::whereIn('id', $accountIds)
            ->where('is_active', true)
            ->get();

        $this->verboseInfo("Found {$accounts->count()} account(s) with positions pending PnL.");

        foreach ($accounts as $account) {
            $resolver = JobProxy::with($account);

            Step::create([
                'class' => $resolver->resolve(FetchAccountPositionsPnlJob::class),
                'queue' => 'cronjobs',
                'relatable_type' => Account::class,
                'relatable_id' => $account->id,
                'arguments' => ['accountId' => $account->id],
                'index' => 1,
            ]);

            $this->verboseComment("  → Dispatched FetchAccountPositionsPnlJob for account #{$account->id}");
        }

        return self::SUCCESS;
    }
}
