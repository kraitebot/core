<?php

declare(strict_types=1);

namespace Kraite\Core\Commands;

use Kraite\Core\Models\Server;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Running;
use StepDispatcher\Support\BaseCommand;

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

        // 3. Check for Running non-parent steps (actively executing work)
        // Parent steps (with child_block_uuid) are just waiting - they can be interrupted.
        // Only child steps (without child_block_uuid) are actively doing work.
        $runningCount = Step::where('state', Running::class)
            ->whereNull('child_block_uuid')
            ->count();

        if ($runningCount > 0) {
            $this->line('false');
            $this->error("❌ {$runningCount} steps are still running");
            $this->warn('⏳ Wait for running steps to complete before deploying');
            $this->warn('💡 Tip: Enable cooling down from the dashboard to stop new dispatches');

            return 1;
        }

        // 4. All clear - safe to deploy
        $this->line('true');
        $this->info('✅ No running steps - safe to deploy');

        return 0;
    }
}
