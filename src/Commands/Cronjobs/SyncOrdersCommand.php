<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Cronjobs;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Kraite\Core\Jobs\Lifecycles\Order\PrepareSyncOrdersJob;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use StepDispatcher\Models\Step;
use StepDispatcher\Support\BaseCommand;
use StepDispatcher\Support\Steps;
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
                            {--force : Required in production environments to authorize bypass-safety operations (--order_id direct sync)}
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
            // Environment gate — same shape as CreatePositionsCommand's
            // --clean. Truncates steps + audit/snapshot tables and wipes
            // log directories. Running on production destroys workflow
            // state and audit trail while the exchange may still hold
            // live positions/orders. Refused outright in any non-local /
            // non-testing environment; if a production reset is ever
            // genuinely needed, that's a separate explicitly-named,
            // audit-logged command, not a flag on the every-five-minute
            // sync cron.
            if (! app()->environment(['local', 'testing'])) {
                $this->error(sprintf(
                    '[SYNC-ORDERS] --clean refused: current environment is "%s". This flag only runs in local or testing.',
                    app()->environment()
                ));

                return self::FAILURE;
            }

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

        // Route every Step::create issued by this cron tick into the
        // `trading_*` table set. The closure pushes `trading_` onto
        // RuntimeContext on entry and pops it on exit (incl. throw),
        // so the entry-step row lands in `trading_steps` AND its
        // serialised job payload carries `stepPrefix='trading_'`.
        // When the Horizon worker picks it up, BaseStepJob::handle()
        // re-pushes that prefix before compute() runs, so any child
        // Step::create the orchestrator spawns also lands in trading_*.
        // Result: the entire sync chain — Prepare, Verify, Query,
        // QueryOrder, BuildClose, etc. — stays inside the prefixed
        // dispatcher end-to-end, isolated from the default workload.
        Steps::usingPrefix('trading', function () use ($openPositions, &$syncedCount): void {
            foreach ($openPositions as $position) {
                // Per-position try/catch — at 200+ open positions, one
                // transient Step::create failure (DB deadlock, Redis blip,
                // observer chain throwing on a corrupt position row) would
                // otherwise abort the whole sync tick and leave the
                // remaining positions un-synced for a full minute. Per-row
                // isolation: the bad row logs, the rest dispatches.
                try {
                    // Don't pre-set child_block_uuid here. The orchestrator's
                    // compute() decides whether to spawn children (early-return
                    // when position isn't 'active' produces no children) and
                    // calls $this->step->makeItAParent() inline at the moment
                    // it actually dispatches its child lifecycle. Pre-setting
                    // would commit the step to parent-mode before compute()
                    // runs, which leaves a zombie when the early-return path
                    // fires. See ~/steps-dispatcher/issue.md.
                    Step::create([
                        'class' => PrepareSyncOrdersJob::class,
                        'queue' => 'positions',
                        'relatable_type' => Position::class,
                        'relatable_id' => $position->id,
                        'arguments' => [
                            'positionId' => $position->id,
                        ],
                    ]);

                    $this->verboseComment("  Position #{$position->id}: Dispatched sync");
                    $syncedCount++;
                } catch (Throwable $e) {
                    Log::channel('jobs')->error('[SYNC-ORDERS] per-position dispatch threw — continuing with the rest', [
                        'position_id' => $position->id,
                        'exception' => $e::class,
                        'message' => $e->getMessage(),
                    ]);

                    $this->verboseWarn("  Position #{$position->id}: dispatch threw, skipped (rest continue)");
                }
            }
        });

        $this->verboseInfo("Total: Dispatched sync for {$syncedCount} position(s)");

        return self::SUCCESS;
    }

    /**
     * Sync a single order by ID — direct apiSync(), no Step dispatch.
     *
     * Bypass-safety contract: this path skips BaseApiableJob throttling,
     * the API exception handler, forbidden-host checks, retry handling,
     * and trading-step audit logging. It is an emergency / manual operator
     * tool, not an operational pipeline. Used carelessly during exchange
     * instability it can hit rate limits, surface raw exceptions to the
     * operator console, and leave no audit trail of the manual sync.
     *
     * Production gate: in non-local/testing environments the operator must
     * pass --force explicitly. This is intentional friction so the command
     * cannot be invoked by an automation script without a deliberate
     * authorization flag — same shape as --clean's environment gate.
     */
    private function syncSingleOrder(int $orderId): int
    {
        if (! app()->environment(['local', 'testing']) && ! $this->option('force')) {
            $this->error(sprintf(
                '[SYNC-ORDERS] --order_id refused: current environment is "%s". This direct-sync path bypasses throttling, the exception handler, forbidden-host checks, retries, and audit logging. Re-run with --force to authorize.',
                app()->environment()
            ));

            return self::FAILURE;
        }

        $this->warn('[SYNC-ORDERS] direct mode active: bypassing throttling, exception handler, forbidden-host checks, retries, and audit logging.');

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
