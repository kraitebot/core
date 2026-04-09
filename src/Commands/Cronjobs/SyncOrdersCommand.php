<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Cronjobs;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kraite\Core\Jobs\Lifecycles\Order\PrepareSyncOrdersJob;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use StepDispatcher\Models\Step;
use StepDispatcher\Support\BaseCommand;
use Throwable;

/**
 * SyncOrdersCommand
 *
 * Syncs orders for ALL open positions regardless of user/account trade status.
 * Even if a user cancels their subscription (can_trade=false), their open
 * positions still need syncing to detect fills and trigger close workflows.
 *
 * The sync updates: quantity, price, status.
 * Business logic (detecting fills, triggering workflows) is handled by the Order Observer.
 */
final class SyncOrdersCommand extends BaseCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kraite:cron-sync-orders
                            {--order_id= : Sync a single order by ID}
                            {--clean : Truncate steps and related tables before running (preserves positions and orders)}
                            {--output : Display command output (silent by default)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Syncs orders (quantity, price, status) for all open positions.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->option('clean')) {
            $this->verboseInfo('Truncating steps and related tables (preserving positions and orders)...');

            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            DB::table('steps')->truncate();
            DB::table('steps_dispatcher_ticks')->truncate();
            DB::table('api_request_logs')->truncate();
            DB::table('api_snapshots')->truncate();
            DB::table('notification_logs')->truncate();
            DB::table('model_logs')->truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            $this->verboseInfo('✓ Tables truncated (positions and orders preserved)');

            cleanLogsFolder();
            $this->verboseInfo('✓ All logs and log directories cleared');

            $this->verboseNewLine();
        }

        // Single order sync mode
        if ($orderId = $this->option('order_id')) {
            return $this->syncSingleOrder((int) $orderId);
        }

        // Full sync mode - all opened positions (regardless of user/account trade status)
        // Get position IDs that have syncable orders
        $positionIdsWithSyncable = Order::query()
            ->syncable()
            ->distinct()
            ->pluck('position_id');

        $openPositions = Position::query()
            ->opened()
            ->whereIn('id', $positionIdsWithSyncable)
            ->get();

        $this->verboseInfo("Found {$openPositions->count()} open position(s) with syncable orders");

        $syncedCount = 0;

        foreach ($openPositions as $position) {
            Step::create([
                'class' => PrepareSyncOrdersJob::class,
                'arguments' => [
                    'positionId' => $position->id,
                ],
                'child_block_uuid' => (string) Str::uuid(),
            ]);

            $this->verboseComment("  Position #{$position->id}: Dispatched sync");
            $syncedCount++;
        }

        $this->verboseInfo("Total: Dispatched sync for {$syncedCount} position(s)");

        return self::SUCCESS;
    }

    /**
     * Sync a single order by ID (direct call, no Step dispatch).
     */
    private function syncSingleOrder(int $orderId): int
    {
        /** @var Order|null $order */
        $order = Order::find($orderId);

        if (! $order) {
            $this->error("Order #{$orderId} not found");

            return self::FAILURE;
        }

        if (! $order->exchange_order_id) {
            $this->error("Order #{$orderId} has no exchange_order_id");

            return self::FAILURE;
        }

        $this->verboseInfo("Syncing order #{$orderId} ({$order->type})...");

        try {
            $order->apiSync();

            $this->info("Order #{$orderId} synced: status={$order->status}, qty={$order->quantity}, price={$order->price}");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error("Failed to sync order #{$orderId}: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
