<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Cronjobs;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Kraite\Core\Jobs\Lifecycles\Order\PrepareSyncOrdersJob;
use Kraite\Core\Jobs\Lifecycles\Position\PrepareCancelOrphanOrdersJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Drift\DriftChecker;
use Kraite\Core\Support\Drift\OrderDriftReport;
use Kraite\Core\Support\Drift\PositionDriftReport;
use Kraite\Core\Support\NotificationService;
use StepDispatcher\Models\Step;
use StepDispatcher\Support\BaseCommand;

/**
 * CheckDriftsCommand
 *
 * Proactive 5-minute "spotter" that audits the bot's view of the world
 * against the exchange's view. Runs on top of the reactive sync-orders
 * cron (which fires every minute) — not as a replacement, but as a
 * safety net for the cases where the reactive loop missed something.
 *
 * Three scopes per cycle:
 *
 * Scope 1 — Active position drift:
 *   - Iterates positions in `active` status that have been QUIET for the
 *     last 10 minutes (no order with updated_at within the window).
 *   - Per account, batches a single drift-check call.
 *   - For every position that comes back as drift / db_only, dispatches
 *     PrepareSyncOrdersJob and sends one `position_drift_detected`
 *     pushover.
 *   - exchange_only pairs (exchange has positions we don't track) and
 *     transient/synced pairs are intentionally skipped.
 *
 * Scope 2 — Orphan open orders:
 *   - Finds orders in NEW / PARTIALLY_FILLED whose parent position is in
 *     closed / cancelled / failed AND has no order touched in the last
 *     10 minutes.
 *   - Per parent position, dispatches CancelSingleAlgoOrderJob for every
 *     orphan and sends one summary `position_orphan_orders_detected`
 *     pushover.
 *
 * Scope 3 — Position structure integrity:
 *   - Iterates every position in `active` status (NO quiet window — a
 *     missing order on an active position is a real anomaly that must
 *     be surfaced as fast as possible).
 *   - Verifies the DB-side order set matches what the position snapshot
 *     promised: 1 MARKET entry + N LIMIT orders (N = total_limit_orders)
 *     + 1 PROFIT-* take profit + 1 STOP-MARKET stop loss, all in
 *     non-terminal status (i.e. not CANCELLED / EXPIRED / REJECTED).
 *   - On any gap: globally halts new opens by flipping
 *     `kraite.allow_opening_positions = false` and sends one
 *     `position_structure_broken` notification per broken position.
 *   - The flag is sticky — recovery is operator-driven only.
 *   - DB-only check; trusts the user-data-stream + sync-orders pipeline
 *     to keep `orders.status` fresh.
 *
 * The 10-minute quiet window exists so Scopes 1 and 2 never fire a
 * concurrent heal while the reactive cron is mid-write on the same
 * position. Scope 3 deliberately skips that filter — it relies on the
 * fact that a position only enters `active` status after the full order
 * set has been physically inserted, so any mismatch on `active` cannot
 * be a creation race.
 */
final class CheckDriftsCommand extends BaseCommand
{
    public const QUIET_WINDOW_MINUTES = 10;

    /**
     * Position statuses considered "non-active" for orphan detection.
     */
    public const ORPHAN_PARENT_STATUSES = ['closed', 'cancelled', 'failed'];

    /**
     * Order statuses considered open on the exchange.
     */
    public const ORPHAN_ORDER_STATUSES = ['NEW', 'PARTIALLY_FILLED'];

    /**
     * Order statuses that count as "no longer live" for structural
     * integrity purposes — a row in any of these states is treated as
     * if the order is gone.
     */
    public const STRUCTURALLY_DEAD_STATUSES = ['CANCELLED', 'EXPIRED', 'REJECTED'];

    /**
     * Position-level pair statuses that warrant a heal dispatch.
     */
    private const HEAL_PAIR_STATUSES = [
        PositionDriftReport::STATUS_DRIFT,
        PositionDriftReport::STATUS_DB_ONLY,
    ];

    protected $signature = 'kraite:cron-check-drifts
                            {--account_id= : Limit the audit to a single account}
                            {--skip-structure-audit : Skip the active-position structural integrity scope (drift + orphan scopes still run)}
                            {--output : Display command output (silent by default)}';

    protected $description = 'Proactive 5-minute drift spotter — audits active positions and orphan orders, dispatches existing healers, notifies admin.';

    public function __construct(private readonly DriftChecker $driftService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $cutoff = Carbon::now()->subMinutes(self::QUIET_WINDOW_MINUTES);

        $accountId = $this->option('account_id');
        $accountFilter = $accountId ? (int) $accountId : null;

        $this->auditActivePositionDrift($cutoff, $accountFilter);
        $this->auditOrphanOrders($cutoff, $accountFilter);

        if (! $this->option('skip-structure-audit')) {
            $this->auditPositionStructureIntegrity($accountFilter);
        }

        return self::SUCCESS;
    }

    /**
     * Scope 1 — drift on active positions.
     */
    private function auditActivePositionDrift(Carbon $cutoff, ?int $accountFilter): void
    {
        $quietPositions = Position::query()
            ->where('status', 'active')
            ->whereDoesntHave('orders', fn ($q) => $q->where('updated_at', '>=', $cutoff))
            ->when($accountFilter, fn ($q) => $q->where('account_id', $accountFilter))
            ->get();

        if ($quietPositions->isEmpty()) {
            $this->verboseInfo('Drift audit: no quiet active positions to inspect.');

            return;
        }

        // Group quiet positions by account so we can batch one
        // drift-check API roundtrip per account regardless of how many
        // positions sit underneath it.
        $byAccount = $quietPositions->groupBy('account_id');

        $this->verboseInfo("Drift audit: {$quietPositions->count()} quiet position(s) across {$byAccount->count()} account(s).");

        foreach ($byAccount as $accountId => $positions) {
            /** @var Account|null $account */
            $account = Account::find($accountId);
            if (! $account) {
                continue;
            }

            $report = $this->driftService->analyseAccount($account);

            $quietIds = $positions->pluck('id')->all();

            foreach ($report->positions as $pair) {
                if (! in_array($pair->status, self::HEAL_PAIR_STATUSES, true)) {
                    continue;
                }
                if ($pair->positionId === null || ! in_array($pair->positionId, $quietIds, true)) {
                    // Either the pair has no DB row (exchange_only — already
                    // filtered above) or the DB row didn't pass the
                    // 10-minute quiet window. Skip.
                    continue;
                }

                // ALERT-ONLY mode (2026-05-03): the drift spotter no
                // longer auto-dispatches the heal — it just surfaces
                // the drift to the operator. The reactive sync-orders
                // cron + WS push path still handle the live healing.
                $this->notifyDrift($account, $pair);
            }
        }
    }

    /**
     * Scope 2 — orphan open orders on non-active positions.
     */
    private function auditOrphanOrders(Carbon $cutoff, ?int $accountFilter): void
    {
        $orphanOrders = Order::query()
            ->whereIn('status', self::ORPHAN_ORDER_STATUSES)
            ->whereHas('position', function ($q) use ($accountFilter) {
                $q->whereIn('status', self::ORPHAN_PARENT_STATUSES);
                if ($accountFilter !== null) {
                    $q->where('account_id', $accountFilter);
                }
            })
            ->with(['position.account.apiSystem'])
            ->get();

        if ($orphanOrders->isEmpty()) {
            $this->verboseInfo('Orphan audit: nothing to clean up.');

            return;
        }

        $byPosition = $orphanOrders->groupBy('position_id');

        $this->verboseInfo("Orphan audit: {$orphanOrders->count()} orphan order(s) across {$byPosition->count()} position(s).");

        foreach ($byPosition as $positionId => $orders) {
            // Skip the whole position if ANY of its orphan orders was
            // touched within the quiet window — the reactive cleanup may
            // still be racing with us.
            $touchedRecently = $orders->contains(fn (Order $o) => $o->updated_at !== null && $o->updated_at->greaterThanOrEqualTo($cutoff));
            if ($touchedRecently) {
                $this->verboseComment("  Position #{$positionId}: skipped (orphan order touched in last ".self::QUIET_WINDOW_MINUTES.'min)');

                continue;
            }

            $position = $orders->first()->position ?? null;
            if (! $position) {
                continue;
            }

            // Surgical silent self-heal (2026-05-03): per orphan
            // candidate carrying an `exchange_order_id`, run a single
            // `apiSync` against the exchange to refresh the local row
            // from whatever Binance / Bitget currently say. Ninety-
            // plus percent of "orphan" candidates we see in
            // production are local-DB staleness — the exchange has
            // the order at FILLED / CANCELLED / EXPIRED but a sync
            // workflow missed it because `SyncPositionOrdersJob`'s
            // `syncable()` scope excludes MARKET orders by design,
            // so the entry-MARKET PARTIALLY_FILLED of a cancelled
            // position never gets reconciled. apiSync resolves that
            // exact case silently — no notification, no lifecycle
            // dispatch, no fan-out. Failures are swallowed per-order
            // so one bad call doesn't abort the audit. Bounded cost:
            // typically 0-1 orphans per 5-min tick × one REST call
            // per orphan.
            //
            // Only orders carrying an `exchange_order_id` get synced.
            // Rows without one ("ghost" orphans — the place flow
            // failed before the exchange ack landed) are left alone
            // here; alert-only mode lets the operator decide.
            foreach ($orders as $candidate) {
                if ($candidate->exchange_order_id === null || $candidate->exchange_order_id === '') {
                    continue;
                }

                try {
                    $candidate->apiSync();
                } catch (\Throwable $exception) {
                    Log::channel('jobs')->warning(
                        '[DRIFT-AUDIT] orphan silent-sync failed',
                        [
                            'order_id' => $candidate->id,
                            'exchange_order_id' => $candidate->exchange_order_id,
                            'error' => $exception->getMessage(),
                        ]
                    );
                }
            }

            // Re-pull the orders fresh from DB, filtered to the
            // still-orphan working set. Any candidate whose post-sync
            // status is terminal (FILLED / CANCELLED / EXPIRED) drops
            // out automatically, and the audit moves on without
            // alerting — the false-positive resolved itself.
            $orders = Order::query()
                ->whereIn('id', $orders->pluck('id')->all())
                ->whereIn('status', self::ORPHAN_ORDER_STATUSES)
                ->get();

            if ($orders->isEmpty()) {
                $this->verboseComment("  Position #{$position->id}: orphan(s) self-healed via apiSync — silent skip.");

                continue;
            }

            // Surviving orphans = exchange genuinely still has the
            // order working with no local active position attached.
            // Surface to the operator. Alert-only — no auto-cancel.
            $this->notifyOrphans(
                position: $position,
                orders: $orders,
                ghostsCancelledInDb: 0,
                cancelLifecycleDispatched: false,
            );
        }
    }

    /**
     * Drop a top-level Step into the queue that the reactive sync-orders
     * cron uses. The step-dispatcher promotes it to Pending on the next
     * tick and the lifecycle does the heal.
     */
    private function dispatchSyncOrders(int $positionId): void
    {
        Step::create([
            'class' => PrepareSyncOrdersJob::class,
            'queue' => 'positions',
            'arguments' => ['positionId' => $positionId],
        ]);

        $this->verboseComment("  Position #{$positionId}: dispatched PrepareSyncOrdersJob");
    }

    /**
     * Hand the orphan parent off to the existing close-workflow cancel
     * machinery. The wrapper Step spawns the
     * CancelPositionOpenOrders lifecycle (bulk-cancel + algo-cancel)
     * the close path already uses, so we get full coverage of regular
     * AND algo orders without re-implementing per-exchange cancel
     * routing here.
     */
    private function dispatchCancelOrphanLifecycle(int $positionId): void
    {
        Step::create([
            'class' => PrepareCancelOrphanOrdersJob::class,
            'queue' => 'positions',
            'arguments' => ['positionId' => $positionId],
        ]);

        $this->verboseComment("  Position #{$positionId}: dispatched PrepareCancelOrphanOrdersJob");
    }

    private function notifyDrift(Account $account, PositionDriftReport $pair): void
    {
        $orderDrifts = array_map(
            static fn (OrderDriftReport $o): array => [
                'id' => is_array($o->db) ? ($o->db['id'] ?? null) : null,
                'type' => is_array($o->db) ? ($o->db['type'] ?? null) : ($o->exchange['type'] ?? null),
                'status' => $o->status,
                'drift_fields' => $o->driftFields,
            ],
            $pair->driftedOrders(),
        );

        NotificationService::send(
            user: Kraite::admin(),
            canonical: 'position_drift_detected',
            referenceData: [
                'account_id' => $account->id,
                'account_name' => $account->name,
                'exchange' => $account->apiSystem?->canonical,
                'position_id' => $pair->positionId,
                'pair' => $pair->symbol,
                'direction' => $pair->direction,
                'pair_status' => $pair->status,
                'position_drift_fields' => $pair->positionDriftFields,
                'order_drifts' => $orderDrifts,
            ],
            relatable: $account,
            cacheKeys: ['position' => $pair->positionId],
        );
    }

    /**
     * @param  EloquentCollection<int, Order>  $orders
     */
    private function notifyOrphans(
        Position $position,
        EloquentCollection $orders,
        int $ghostsCancelledInDb = 0,
        bool $cancelLifecycleDispatched = false,
    ): void {
        $account = $position->account;

        $orphanList = $orders->map(fn (Order $o) => [
            'id' => $o->id,
            'type' => $o->type,
            'side' => $o->side,
            'status' => $o->status,
            'is_algo' => (bool) $o->is_algo,
            'is_ghost' => $o->exchange_order_id === null || $o->exchange_order_id === '',
            // Exchange-side identifiers so the operator can paste them
            // directly into Binance's order-history search rather than
            // chasing the local id back to its exchange counterpart.
            'exchange_order_id' => $o->exchange_order_id,
            'client_order_id' => $o->client_order_id,
        ])->all();

        NotificationService::send(
            user: Kraite::admin(),
            canonical: 'position_orphan_orders_detected',
            referenceData: [
                'account_id' => $account?->id,
                'account_name' => $account?->name,
                'exchange' => $account?->apiSystem?->canonical,
                'position_id' => $position->id,
                'pair' => $position->parsed_trading_pair,
                'direction' => $position->direction,
                'position_status' => $position->status,
                'orphan_orders' => $orphanList,
                'ghosts_cancelled_in_db' => $ghostsCancelledInDb,
                'cancel_lifecycle_dispatched' => $cancelLifecycleDispatched,
            ],
            relatable: $position,
            cacheKeys: ['position' => $position->id],
        );
    }

    /**
     * Scope 3 — structural integrity of every active position.
     *
     * Walks each `active` position in the DB (no quiet window — by the
     * time a position is `active` its full order set must already exist)
     * and verifies the live row count per category against what the
     * position snapshot promised at open. Any gap halts new opens
     * globally and notifies the operator.
     */
    private function auditPositionStructureIntegrity(?int $accountFilter): void
    {
        $activePositions = Position::query()
            ->where('status', 'active')
            ->when($accountFilter, fn ($q) => $q->where('account_id', $accountFilter))
            ->with(['orders', 'account.apiSystem'])
            ->get();

        if ($activePositions->isEmpty()) {
            $this->verboseInfo('Structure audit: no active positions to inspect.');

            return;
        }

        $this->verboseInfo("Structure audit: inspecting {$activePositions->count()} active position(s).");

        foreach ($activePositions as $position) {
            $missing = $this->detectMissingStructure($position);

            if ($missing === []) {
                continue;
            }

            $this->verboseComment("  Position #{$position->id}: missing ".implode(', ', array_keys($missing)));

            $this->haltOpensGlobally($position);
            $this->notifyStructureBroken($position, $missing);
        }
    }

    /**
     * Compare the live order set on a position against its snapshot
     * promise. A row counts as missing when it's physically absent OR
     * its status is in `STRUCTURALLY_DEAD_STATUSES`.
     *
     * Returns a map of category → human label for every missing
     * component, e.g. ['MARKET' => 'market entry', 'LIMITS' => '3/4 limits live'].
     * Empty array means the position is structurally whole.
     *
     * @return array<string, string>
     */
    private function detectMissingStructure(Position $position): array
    {
        $missing = [];

        $market = $position->marketOrder();
        if ($market === null || in_array($market->status, self::STRUCTURALLY_DEAD_STATUSES, true)) {
            $missing['MARKET'] = 'market entry order';
        }

        if ($position->profitOrder() === null) {
            $missing['TAKE_PROFIT'] = 'take-profit order';
        }

        $stop = $position->stopMarketOrder();
        if ($stop === null || in_array($stop->status, self::STRUCTURALLY_DEAD_STATUSES, true)) {
            $missing['STOP_LOSS'] = 'stop-loss order';
        }

        $expectedLimits = (int) $position->total_limit_orders;
        $liveLimits = $position->limitOrders()->count();
        if ($liveLimits < $expectedLimits) {
            $missing['LIMITS'] = "{$liveLimits}/{$expectedLimits} limit orders live";
        }

        return $missing;
    }

    /**
     * Flip `kraite.allow_opening_positions` to false so the existing
     * `HasTradingGuards` gate halts every new-position dispatch path
     * across all accounts. Idempotent — already-false stays false. The
     * flag is sticky on purpose: only an operator clears it after
     * inspecting the broken position.
     */
    private function haltOpensGlobally(Position $position): void
    {
        $engine = Kraite::query()->first();
        if ($engine === null) {
            return;
        }

        if ((bool) $engine->allow_opening_positions === false) {
            return;
        }

        $engine->update(['allow_opening_positions' => false]);

        $this->verboseComment("  Position #{$position->id}: kraite.allow_opening_positions flipped to false (global open halt).");
    }

    /**
     * @param  array<string, string>  $missing
     */
    private function notifyStructureBroken(Position $position, array $missing): void
    {
        $account = $position->account;

        NotificationService::send(
            user: Kraite::admin(),
            canonical: 'position_structure_broken',
            referenceData: [
                'account_id' => $account?->id,
                'account_name' => $account?->name,
                'exchange' => $account?->apiSystem?->canonical,
                'position_id' => $position->id,
                'pair' => $position->parsed_trading_pair,
                'direction' => $position->direction,
                'expected_limit_orders' => (int) $position->total_limit_orders,
                'missing' => $missing,
            ],
            relatable: $position,
            cacheKeys: ['position' => $position->id],
        );
    }
}
