<?php

declare(strict_types=1);

namespace Kraite\Core\Commands;

use Kraite\Core\Models\Server;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Dispatched;
use StepDispatcher\States\Running;
use StepDispatcher\Support\BaseCommand;
use StepDispatcher\Support\Steps;

final class SafeToRestartCommand extends BaseCommand
{
    protected $signature = 'kraite:safe-to-restart';

    protected $description = 'Check if it is safe to restart Horizon/queues (returns true or false)';

    public function handle(): int
    {
        // 1. Get current hostname
        $hostname = gethostname();

        // 2. Validate hostname exists in servers table and is apiable
        $serverExists = Server::where('hostname', $hostname)
            ->where('is_apiable', true)
            ->exists();

        if (! $serverExists) {
            $this->line('false');
            $this->error("❌ Unknown hostname '{$hostname}' - not found in apiable servers");
            $this->warn('Valid hostnames: '.Server::where('is_apiable', true)->pluck('hostname')->implode(', '));
            $this->warn('⛔ Deployment blocked for security');

            return 1;
        }

        // 3. Inspect BOTH the default `steps` table AND the `trading_steps`
        // prefix. Pre-fix, only the default prefix was queried — major
        // trading lifecycles run under the `trading` step prefix, so a
        // deployment guard that only saw default could report safe while
        // active trading work was in flight.
        // Also count `Dispatched` rows: those have been picked up by a
        // worker but not yet flipped to Running. Killing them mid-handoff
        // is the same blast radius as killing Running.
        $countActive = static fn (): int => (int) Step::query()
            ->whereIn('state', [Running::class, Dispatched::class])
            ->whereNull('child_block_uuid')
            ->count();

        $defaultCount = $countActive();
        $tradingCount = (int) Steps::usingPrefix('trading', $countActive);
        $totalActive = $defaultCount + $tradingCount;

        if ($totalActive > 0) {
            $this->line('false');
            $this->error("❌ {$totalActive} step(s) still active — default={$defaultCount}, trading={$tradingCount}");
            $this->warn('⏳ Wait for active steps to complete before deploying');
            $this->warn('💡 Tip: Enable cooling down from the dashboard to stop new dispatches');

            return 1;
        }

        // 4. All clear - safe to deploy
        $this->line('true');
        $this->info('✅ No active steps in default or trading prefixes — safe to deploy');

        return 0;
    }
}
