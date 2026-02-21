<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Debug;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kraite\Core\Jobs\Lifecycles\Position\DispatchPositionJob;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Proxies\JobProxy;
use StepDispatcher\Models\Step;
use StepDispatcher\Support\BaseCommand;

/**
 * Debug command to retry position dispatching workflow.
 *
 * Use this to test market order placement without creating new positions.
 * Deletes existing orders and re-dispatches the full position workflow.
 */
final class RetryPositionDispatchCommand extends BaseCommand
{
    protected $signature = 'debug:retry-position-dispatch
                            {position_id : The position ID to retry}
                            {--clean : Truncate auxiliary tables (steps, api_snapshots, etc.) before retrying}
                            {--keep-orders : Do not delete existing orders before retrying}
                            {--only-market-order : Only dispatch the PlaceMarketOrderJob step}';

    protected $description = 'Retry the position dispatch workflow for an existing position (for testing market order placement)';

    public function handle(): int
    {
        $positionId = (int) $this->argument('position_id');

        /** @var Position|null $position */
        $position = Position::find($positionId);

        if (! $position) {
            $this->error("Position #{$positionId} not found");

            return self::FAILURE;
        }

        /** @var int $ordersCount */
        $ordersCount = $position->orders()->count(); // @phpstan-ignore method.nonObject

        $this->info("Position #{$position->id}");
        $this->info("  Token: {$position->exchangeSymbol->parsed_trading_pair}");
        // @phpstan-ignore-next-line
        $this->info("  Direction: {$position->direction}");
        // @phpstan-ignore-next-line
        $this->info("  Status: {$position->status}");
        // @phpstan-ignore-next-line
        $this->info("  Margin: {$position->margin}");
        // @phpstan-ignore-next-line
        $this->info("  Leverage: {$position->leverage}");
        $this->info("  Orders: {$ordersCount}");

        // Truncate auxiliary tables if --clean is passed (but NOT positions/orders)
        if ($this->option('clean')) {
            $this->newLine();
            $this->warn('Truncating auxiliary tables...');

            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            DB::table('steps')->truncate();
            DB::table('steps_dispatcher_ticks')->truncate();
            DB::table('api_request_logs')->truncate();
            DB::table('api_snapshots')->truncate();
            DB::table('notification_logs')->truncate();
            DB::table('model_logs')->truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            $this->info('  ✓ Tables truncated (steps, api_snapshots, api_request_logs, notification_logs, model_logs)');

            cleanLogsFolder();
            $this->info('  ✓ Logs folder cleared');
        }

        // Delete existing orders unless --keep-orders is passed
        if (! $this->option('keep-orders') && $ordersCount > 0) {
            $this->warn("Deleting {$ordersCount} existing order(s)...");
            $position->orders()->delete(); // @phpstan-ignore method.nonObject
            $this->info('  ✓ Orders deleted');
        }

        // Truncate steps table for clean retry (only if --clean wasn't used, which already truncated it)
        if (! $this->option('clean')) {
            $this->warn('Truncating steps table...');
            Step::truncate();
            $this->info('  ✓ Steps truncated');
        }

        // Reset position status to 'new' so the guard (startOrStop) passes
        // DispatchPositionJob requires status='new' to proceed
        // @phpstan-ignore-next-line
        if ($position->status !== 'new') {
            $this->warn('Resetting position status to "new"...');
            $position->update(['status' => 'new']);
            $this->info('  ✓ Status reset');
        }

        // Resolve exchange-specific DispatchPositionJob
        $resolver = JobProxy::with($position->account);
        $dispatchJobClass = $resolver->resolve(DispatchPositionJob::class);

        // Create step for DispatchPositionJob
        $blockUuid = (string) Str::uuid();

        Step::create([
            'class' => $dispatchJobClass,
            'arguments' => ['positionId' => $position->id],
            'block_uuid' => $blockUuid,
            'child_block_uuid' => (string) Str::uuid(),
            'index' => 1,
        ]);

        $this->newLine();
        $this->info("✓ Dispatched {$dispatchJobClass}");
        $this->info("  Block UUID: {$blockUuid}");
        $this->newLine();
        $this->comment('Steps will be processed by the dispatcher (supervisor).');
        $this->comment('Run: php artisan tinker --execute="...\Step::pluck(\'state\', \'id\')" to check progress.');

        return self::SUCCESS;
    }
}
