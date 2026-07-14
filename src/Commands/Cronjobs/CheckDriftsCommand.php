<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Cronjobs;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Kraite\Core\Jobs\Lifecycles\Position\ApplyWapJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Drift\DriftChecker;
use Kraite\Core\Support\Drift\OrderDriftReport;
use Kraite\Core\Support\Drift\PositionDriftReport;
use Kraite\Core\Support\MaintenanceMode;
use Kraite\Core\Support\NotificationService;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Dispatched;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;
use StepDispatcher\Support\BaseCommand;
use StepDispatcher\Support\Steps;
use Throwable;

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
 * Scope 2b — WAP under-application self-heal (the ONE scope that heals):
 *   - DB-only detector, `active` positions exclusively — mid-flight
 *     statuses (syncing / waping / closing / ...) are skipped so the
 *     heal never races a workflow that is already mid-write on the row.
 *   - A position whose FILLED entry ladder (MARKET + DCA LIMITs) totals
 *     MORE quantity than its resting take-profit covers is a stuck WAP:
 *     the DCA fill landed on the exchange but the TP resize never
 *     committed (e.g. the resize crashed after the exchange had already
 *     absorbed the fill — 2026-07-13, position #394 FILUSDT, TP left at
 *     47.3 against a 141.9 exchange position).
 *   - Heal = re-dispatch ApplyWapJob, the same idempotent lifecycle the
 *     order observer uses on a LIMIT fill. Its chain re-verifies
 *     everything against a fresh exchange snapshot before modifying the
 *     TP, so dispatching from a stale local view is safe. Dedupe mirrors
 *     the observer: position-row lock + skip when an ApplyWapJob step is
 *     already pending/dispatched/running. Terminal (failed) prior steps
 *     do NOT block — that IS the case being healed.
 *   - No quiet window: the reactive sync touches busy positions every
 *     few minutes, so a quiet-window gate would hide exactly the
 *     positions that need this heal (it hid FILUSDT).
 *   - Re-fires every 5 minutes (with one pushover each pass) while the
 *     under-coverage persists, e.g. if the heal chain keeps failing.
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
     * Position-level pair statuses that warrant an operator alert.
     * Renamed from HEAL_PAIR_STATUSES — the command is alert-only;
     * actual heal dispatch is no longer wired in here. See class
     * docblock for rationale.
     */
    private const ALERT_PAIR_STATUSES = [
        PositionDriftReport::STATUS_DRIFT,
        PositionDriftReport::STATUS_DB_ONLY,
    ];

    protected $signature = 'kraite:cron-check-drifts
                            {--account_id= : Limit the audit to a single account}
                            {--skip-structure-audit : Skip the active-position structural integrity scope (drift + orphan scopes still run)}
                            {--skip-engine-health : Skip the Scope 4 trading-engine-health cooldown scope}
                            {--skip-wap-heal : Skip the Scope 2b WAP under-application self-heal}
                            {--output : Display command output (silent by default)}';

    protected $description = '5-minute drift spotter — audits active positions and orphan orders, notifies admin. Alert-only except Scope 2b: a stuck WAP (TP under-covering the filled DCA ladder) self-heals via ApplyWapJob.';

    public function __construct(private readonly DriftChecker $driftService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        // Drift audit heals every detected mismatch by dispatching a
        // step under the trading prefix (PrepareSyncOrdersJob,
        // PrepareCancelOrphanOrdersJob, …). While the trading
        // dispatcher is paused those heals queue but never drain —
        // every subsequent 5-minute pass re-detects the same drift,
        // fires the same Pushover, AND (Scope 3) could flip
        // `allow_opening_positions=false` on a stale view of the
        // world. Skip the entire pass while trading is paused (the
        // all-scope pause also flips this true via
        // `isStepsDispatchPaused('trading')`); the reactive sync-
        // orders cron + drift checker resume normally the moment
        // trading resumes. Pausing only the default dispatcher does
        // NOT skip drift — drift heals run on trading, so the audit
        // remains safe.
        if (MaintenanceMode::isStepsDispatchPaused('trading')) {
            $this->verboseInfo('Drift audit skipped — trading steps:dispatch is paused.');

            return self::SUCCESS;
        }

        $cutoff = Carbon::now()->subMinutes(self::QUIET_WINDOW_MINUTES);

        $accountId = $this->option('account_id');
        $accountFilter = $accountId ? (int) $accountId : null;

        $this->auditActivePositionDrift($cutoff, $accountFilter);
        $this->auditOrphanOrders($cutoff, $accountFilter);

        // Scope 2b runs BEFORE the cooled latch on purpose: like Scopes
        // 1 + 2 it maintains EXISTING positions, which keep trading and
        // must stay correctly protected even while opening is halted.
        if (! $this->option('skip-wap-heal')) {
            $this->auditWapUnderApplication($accountFilter);
        }

        // Scopes 3 + 4 are the cooling detectors. Once the bot is already
        // cooled (an incident is open), they'd only re-detect the same
        // thing — so we latch: skip them while cooled. This is the
        // "alarm once, wait for Bruno" contract. Drift/orphan heals
        // (Scopes 1 + 2) keep running — they maintain existing positions,
        // which still trade and close normally while opening is halted.
        if ($this->isCooled()) {
            $this->verboseInfo('Cooling detectors skipped — already cooled (incident open, awaiting operator).');

            return self::SUCCESS;
        }

        if (! $this->option('skip-structure-audit')) {
            $this->auditPositionStructureIntegrity($accountFilter);
        }

        if (config('kraite.guard.engine_health_enabled') && ! $this->option('skip-engine-health')) {
            $this->auditTradingEngineHealth();
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

            // If the exchange snapshot itself failed, the report's
            // empty exchange arrays are NOT real "no positions / no
            // orders" — they're "we couldn't see". Classifying that as
            // db_only drift produces noisy false alerts (and would be
            // dangerous if auto-healing is ever re-enabled, since
            // repairs would dispatch off a failed snapshot). Surface
            // the snapshot failure as its own signal and skip drift
            // classification for this account.
            if ($report->apiError !== null) {
                $this->notifySnapshotFailed($account, $report->apiError);

                continue;
            }

            $quietIds = $positions->pluck('id')->all();

            foreach ($report->positions as $pair) {
                // EXCHANGE_ONLY pairs (live exchange position with no
                // local DB row) are the highest-risk drift state — the
                // bot's account-level exposure is no longer trustworthy.
                // Surface these even when the DB-side has no positionId
                // and bypass the quiet-window filter (the position
                // genuinely doesn't exist locally to be quiet about).
                if ($pair->status === PositionDriftReport::STATUS_EXCHANGE_ONLY) {
                    $this->notifyExchangeOnly($account, $pair);

                    continue;
                }

                if (! in_array($pair->status, self::ALERT_PAIR_STATUSES, true)) {
                    continue;
                }
                if ($pair->positionId === null || ! in_array($pair->positionId, $quietIds, true)) {
                    // Either the pair has no DB row (already handled above
                    // for exchange_only) or the DB row didn't pass the
                    // 10-minute quiet window. Skip.
                    continue;
                }

                // ALERT-ONLY mode (2026-05-03): the drift spotter no
                // longer auto-dispatches the heal — it just surfaces
                // the drift to the operator. The reactive sync-orders
                // cron + WS push path still handle the live healing.
                $this->notifyDrift($account, $pair);
            }

            // Surface exchange-side orders that don't match any DB row.
            // The Scope 2 orphan audit below is DB-first and only finds
            // local orders whose parent position is closed; this catches
            // the opposite case (an exchange-side order with no DB at
            // all — manual placement, mapper bug, partial-write crash).
            if ($report->orphanOrders !== []) {
                $this->notifyExchangeOnlyOrders($account, $report->orphanOrders);
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
                } catch (Throwable $exception) {
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
     * Scope 2b — WAP under-application self-heal.
     *
     * DB-only detector (no exchange roundtrip): on every `active`
     * position, the FILLED entry ladder (MARKET + DCA LIMITs) must be
     * fully covered by the resting take-profit's quantity. When fills
     * total more than the TP covers, a WAP resize was lost mid-flight —
     * the exchange absorbed the DCA fill but the TP never grew. The heal
     * re-dispatches ApplyWapJob, whose chain re-verifies everything
     * against a fresh exchange snapshot before touching the TP.
     *
     * Deliberately `active`-only: mid-flight statuses (syncing / waping /
     * closing / ...) mean a workflow already owns the row — by the time
     * it releases the position back to `active`, either the TP is
     * correct or this scope catches it on the next 5-minute pass.
     */
    private function auditWapUnderApplication(?int $accountFilter): void
    {
        $activePositions = Position::query()
            ->where('status', 'active')
            ->when($accountFilter, fn ($q) => $q->where('account_id', $accountFilter))
            ->with(['orders', 'exchangeSymbol', 'account.apiSystem'])
            ->get();

        if ($activePositions->isEmpty()) {
            $this->verboseInfo('WAP heal audit: no active positions to inspect.');

            return;
        }

        foreach ($activePositions as $position) {
            // TP missing entirely is the structure audit's scope; a TP
            // that is no longer resting NEW (partially filled / filled)
            // means an exit is in flight and the close workflow owns the
            // position — modifying its quantity now would fight the exit.
            $profitOrder = $position->profitOrder();
            if ($profitOrder === null || $profitOrder->status !== 'NEW') {
                continue;
            }

            // The WAP signature requires at least one FILLED DCA LIMIT —
            // an under-sized TP with no DCA fill behind it is a different
            // anomaly and not this scope's call to repair.
            $hasFilledLimit = $position->orders->contains(function (Order $order): bool {
                return $order->type === 'LIMIT' && $order->status === 'FILLED';
            });

            if (! $hasFilledLimit) {
                continue;
            }

            // Mirror of CalculateWapAndModifyProfitOrderJob::expectedPositionQty —
            // the minimum quantity the TP must cover. Formatted through the
            // symbol's quantity precision so truncation the exchange itself
            // applies (141.94 → 141.9) never reads as under-coverage.
            $filledQty = $position->orders
                ->filter(function (Order $order): bool {
                    return in_array($order->type, ['MARKET', 'LIMIT'], true)
                        && $order->status === 'FILLED';
                })
                ->reduce(function (string $carry, Order $order): string {
                    return bcadd($carry, (string) $order->quantity, 18);
                }, '0');

            $expectedQty = api_format_quantity($filledQty, $position->exchangeSymbol);

            if (bccomp($expectedQty, (string) $profitOrder->quantity, 18) <= 0) {
                continue;
            }

            $this->verboseComment(
                "  Position #{$position->id} ({$position->parsed_trading_pair}): ".
                "TP covers {$profitOrder->quantity}, fills total {$expectedQty} — dispatching WAP heal."
            );

            if ($this->dispatchWapHeal($position)) {
                $this->notifyWapSelfHealed($position, (string) $profitOrder->quantity, $expectedQty);
            }
        }
    }

    /**
     * Dispatch the ApplyWapJob heal for one under-covered position.
     *
     * Exact mirror of OrderObserver::dispatchApplyWap's concurrency
     * discipline: position-row lock inside a transaction serialises us
     * against the observer and against a concurrent spotter run; the
     * pending-step dedupe makes the dispatch idempotent. Terminal states
     * (Completed / Failed / Skipped) intentionally do NOT block — a
     * previously FAILED ApplyWapJob is precisely the wound being healed.
     *
     * @return bool true when a fresh heal step was created
     */
    private function dispatchWapHeal(Position $position): bool
    {
        return (bool) Steps::usingPrefix('trading', function () use ($position): bool {
            return DB::transaction(function () use ($position): bool {
                $locked = Position::query()->whereKey($position->id)->lockForUpdate()->first();

                if ($locked === null || $locked->status !== 'active') {
                    return false;
                }

                $alreadyPending = Step::query()
                    ->where('class', ApplyWapJob::class)
                    ->whereRaw("JSON_EXTRACT(arguments, '$.positionId') = ?", [$position->id])
                    ->whereIn('state', [Pending::class, Dispatched::class, Running::class])
                    ->exists();

                if ($alreadyPending) {
                    return false;
                }

                Step::create([
                    'class' => ApplyWapJob::class,
                    'queue' => 'positions',
                    'priority' => 'high',
                    'arguments' => [
                        'positionId' => $locked->id,
                        'message' => 'Drift spotter self-heal: TP quantity under-covers the filled DCA ladder — re-applying WAP',
                    ],
                ]);

                return true;
            });
        });
    }

    /**
     * Audit trail for the Scope 2b heal — one pushover per dispatched
     * heal so the operator knows a stuck WAP was found and is being
     * repaired. Re-fires on subsequent passes while the under-coverage
     * persists, which doubles as the "the heal itself keeps failing"
     * alarm.
     */
    private function notifyWapSelfHealed(Position $position, string $tpQuantity, string $expectedQuantity): void
    {
        $account = $position->account;

        NotificationService::send(
            user: Kraite::admin(),
            canonical: 'position_wap_self_healed',
            referenceData: [
                'account_id' => $account?->id,
                'account_name' => $account?->name,
                'exchange' => $account?->apiSystem?->canonical,
                'position_id' => $position->id,
                'pair' => $position->parsed_trading_pair,
                'direction' => $position->direction,
                'tp_quantity' => $tpQuantity,
                'expected_quantity' => $expectedQuantity,
            ],
            relatable: $position,
            cacheKeys: ['position' => $position->id],
        );
    }

    /**
     * Surface exchange-side orders that have no matching DB row. These
     * can come from manual exchange-side placement, a mapper bug, or a
     * partial-write crash where exchange ack landed but local persist
     * failed. Distinct from Scope 2's orphan audit which is DB-first
     * (finds local orders whose parent is closed). One summary
     * notification per account regardless of order count.
     *
     * @param  array<int, array<string, mixed>>  $orphans
     */
    private function notifyExchangeOnlyOrders(Account $account, array $orphans): void
    {
        NotificationService::send(
            user: Kraite::admin(),
            canonical: 'orders_exchange_only_detected',
            referenceData: [
                'account_id' => $account->id,
                'account_name' => $account->name,
                'exchange' => $account->apiSystem?->canonical,
                'orphan_count' => count($orphans),
                'orphans' => array_slice($orphans, 0, 25),
            ],
            relatable: $account,
            cacheKeys: ['account' => $account->id],
        );
    }

    /**
     * Surface an exchange-only position (live exchange position with no
     * local DB row) as a first-class alert. This is one of the highest
     * risk drift states — the bot's account-level exposure can no longer
     * be trusted while the unknown position is open. Operator
     * remediation: investigate, then either record the missing position
     * via recovery or close it manually on the exchange.
     */
    private function notifyExchangeOnly(Account $account, PositionDriftReport $pair): void
    {
        NotificationService::send(
            user: Kraite::admin(),
            canonical: 'position_exchange_only_detected',
            referenceData: [
                'account_id' => $account->id,
                'account_name' => $account->name,
                'exchange' => $account->apiSystem?->canonical,
                'pair' => $pair->symbol,
                'direction' => $pair->direction,
                'exchange_data' => $pair->exchange,
            ],
            relatable: $account,
            cacheKeys: [
                'account' => $account->id,
                'symbol' => $pair->symbol,
                'direction' => $pair->direction,
            ],
        );
    }

    /**
     * Surface a failed exchange snapshot as its own first-class signal.
     * Uses a dedicated canonical so the operator can tell "exchange
     * unavailable" from "bot state drifted" — the two require very
     * different remediation paths.
     */
    private function notifySnapshotFailed(Account $account, string $apiError): void
    {
        NotificationService::send(
            user: Kraite::admin(),
            canonical: 'account_drift_snapshot_failed',
            referenceData: [
                'account_id' => $account->id,
                'account_name' => $account->name,
                'exchange' => $account->apiSystem?->canonical,
                'api_error' => $apiError,
            ],
            relatable: $account,
            cacheKeys: ['account' => $account->id],
        );
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

        $brokenReport = [];

        foreach ($activePositions as $position) {
            // Exit-in-progress guard. A position whose take-profit or
            // stop-loss has already FILLED/TRIGGERED is closing: the close
            // workflow cancels its remaining orders (limits, the opposite
            // protective leg) as a normal part of the exit, so a structural
            // "gap" here is EXPECTED, not a bug. It is only still `active`
            // for the sub-second window before the status flips to
            // `closing`/`closed`. Flagging it wrongly cooled the whole bot
            // for 6h on a winning TP-close (position #503, 2026-07-12).
            // Skip — a fired exit is never a broken structure.
            if ($this->exitHasFired($position)) {
                $this->verboseComment("  Position #{$position->id}: exit fired (TP/SL FILLED/TRIGGERED) — skipping structure check (closing).");

                continue;
            }

            $missing = $this->detectMissingStructure($position);

            if ($missing === []) {
                continue;
            }

            $this->verboseComment("  Position #{$position->id}: missing ".implode(', ', array_keys($missing)));

            $this->notifyStructureBroken($position, $missing);

            $brokenReport[] = [
                'position_id' => $position->id,
                'pair' => $position->parsed_trading_pair,
                'missing' => array_keys($missing),
            ];
        }

        if ($brokenReport !== []) {
            $this->enterCooldown('position_structure_broken', ['broken_positions' => $brokenReport]);
        }
    }

    /**
     * Scope 4 — trading-engine health. Deterministic money-danger signals
     * beyond structure/drift: a burst of failed positions, a storm of
     * failed trading steps, or rapid-fire exchange errors in the log. Any
     * one over its (conservative, config-driven) threshold enters cooldown.
     * The LLM narrator never decides this — it only documents it after.
     */
    private function auditTradingEngineHealth(): void
    {
        $windowMinutes = (int) config('kraite.guard.window_minutes', 20);
        $since = Carbon::now()->subMinutes($windowMinutes);

        // Signal A — fresh failed positions. A position should close, not
        // fail; a cluster of fresh failures signals a systemic problem.
        $failedThreshold = (int) config('kraite.guard.failed_positions_threshold', 2);
        $failedPositions = Position::query()
            ->where('status', 'failed')
            ->where('updated_at', '>', $since)
            ->count();

        if ($failedPositions >= $failedThreshold) {
            $this->enterCooldown('failed_positions_burst', [
                'failed_positions' => $failedPositions,
                'threshold' => $failedThreshold,
                'window_minutes' => $windowMinutes,
            ]);

            return;
        }

        // Signal B — failed trading-step storm. The engine choking on the
        // money path. Threshold sits well above benign TAAPI/kline noise.
        $stepThreshold = (int) config('kraite.guard.failed_steps_threshold', 25);
        $failedSteps = DB::table('trading_steps')
            ->where('state', 'StepDispatcher\\States\\Failed')
            ->where('updated_at', '>', $since)
            ->count();

        if ($failedSteps >= $stepThreshold) {
            $this->enterCooldown('failed_steps_storm', [
                'failed_steps' => $failedSteps,
                'threshold' => $stepThreshold,
                'window_minutes' => $windowMinutes,
            ]);

            return;
        }

        // Signal C — rapid-fire exchange errors in the Laravel log. Counting
        // matching lines is deterministic; the LLM narrates, never decides.
        $exchangeErrorThreshold = (int) config('kraite.guard.exchange_error_log_threshold', 15);
        $exchangeErrors = $this->countRecentExchangeErrorsInLog($since);

        if ($exchangeErrors >= $exchangeErrorThreshold) {
            $this->enterCooldown('exchange_error_storm', [
                'exchange_errors' => $exchangeErrors,
                'threshold' => $exchangeErrorThreshold,
                'window_minutes' => $windowMinutes,
            ]);
        }
    }

    /**
     * Count exchange-facing API error lines in today's Laravel log within
     * the window. Deliberately simple + defensive: reads only the tail,
     * never throws (a log-read failure must never break the safety cron).
     */
    private function countRecentExchangeErrorsInLog(Carbon $since): int
    {
        try {
            $logFile = storage_path('logs/laravel-'.Carbon::now()->format('Y-m-d').'.log');
            if (! File::exists($logFile)) {
                return 0;
            }

            // Read the last ~4000 lines only — a storm is recent and dense.
            $lines = array_slice(file($logFile) ?: [], -4000);
            $count = 0;

            foreach ($lines as $line) {
                // Only lines that look like a timestamped ERROR mentioning an
                // exchange-side failure. Keep the pattern broad but anchored
                // to error level so INFO/DEBUG noise never counts.
                if (! str_contains($line, '.ERROR')) {
                    continue;
                }
                if (preg_match('/binance|bybit|bitget|kucoin|exchange|api.?error|-\d{4}\b/i', $line) !== 1) {
                    continue;
                }
                if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $line, $m) === 1) {
                    if (Carbon::parse($m[1])->greaterThan($since)) {
                        $count++;
                    }
                }
            }

            return $count;
        } catch (Throwable $e) {
            Log::warning('[monitor-guard] exchange-error log scan failed; treating as 0', [
                'message' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * True when the position's exit has already fired — any take-profit
     * or stop-loss order in FILLED / TRIGGERED. Such a position is
     * closing, so a missing protective leg is the normal consequence of
     * the close workflow cancelling orders, not a structural break.
     */
    private function exitHasFired(Position $position): bool
    {
        return $position->orders
            ->whereIn('type', ['PROFIT-LIMIT', 'PROFIT-MARKET', 'STOP-MARKET'])
            ->whereIn('status', ['FILLED', 'TRIGGERED'])
            ->isNotEmpty();
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
     * True when opening is already halted (the bot is cooled), whatever
     * the cause. Used as the latch: once cooled, the cooling detectors
     * (Scopes 3 + 4) skip so they never re-alarm the same incident.
     */
    private function isCooled(): bool
    {
        $value = Kraite::query()->value('allow_opening_positions');

        return $value !== null && (bool) $value === false;
    }

    /**
     * Enter cooldown: globally halt opening new positions and write one
     * incident file for later operator review. Idempotent + alarm-once:
     * only the true→false transition writes an incident and marks the
     * latch. The flag is sticky — only the operator clears it after the
     * fix. `HasTradingGuards` reads the flag and blocks every new-position
     * dispatch path across all accounts.
     *
     * @param  array<string, mixed>  $evidence
     */
    private function enterCooldown(string $reason, array $evidence): void
    {
        $engine = Kraite::query()->first();
        if ($engine === null) {
            return;
        }

        // Latch — already cooled. Never re-cool, never re-write an incident.
        if ((bool) $engine->allow_opening_positions === false) {
            return;
        }

        $engine->update(['allow_opening_positions' => false]);

        $this->verboseComment("  Cooldown entered ({$reason}) — allow_opening_positions=false (global open halt).");

        $this->writeIncident($reason, $evidence);
        $this->sendGuardPushover($reason, $evidence);
    }

    /**
     * The money-guard's own one-shot Pushover. Sent direct (not through the
     * notification-template system) so a money-critical alarm never depends
     * on template/DB state being healthy. Fires once per incident (called
     * only on the true→false cooldown transition). Never throws.
     *
     * @param  array<string, mixed>  $evidence
     */
    private function sendGuardPushover(string $reason, array $evidence): void
    {
        try {
            $token = (string) config('kraite.admin_user_pushover_application_key');
            $user = (string) config('kraite.admin_user_pushover_user_key');
            if ($token === '' || $user === '') {
                return;
            }

            $summary = collect($evidence)
                ->map(fn ($v, $k) => is_scalar($v) ? "{$k}={$v}" : $k)
                ->implode(', ');

            Http::asForm()->timeout(10)->post('https://api.pushover.net/1/messages.json', [
                'token' => $token,
                'user' => $user,
                'priority' => 1,
                'title' => 'KRAITE COOLED — opens halted',
                'message' => "Trigger: {$reason}\n{$summary}\n\nBot stopped opening new positions. Existing positions safe. Incident file written for review.",
            ]);
        } catch (Throwable $e) {
            Log::error('[monitor-guard] guard pushover failed (cooldown still applied)', [
                'reason' => $reason,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Write the deterministic incident stub to `monitoring/<ts>.md` and
     * point the `monitoring/OPEN-INCIDENT` marker at it. The stub stands
     * alone if the Haiku narrator never runs; the narrator later expands
     * it in place. Never throws — a documentation failure must not break
     * the safety cron (the cooldown itself already happened).
     */
    private function writeIncident(string $reason, array $evidence): void
    {
        try {
            $dir = base_path('monitoring');
            File::ensureDirectoryExists($dir);

            $now = Carbon::now();
            $stamp = $now->format('Ymd_His');
            $file = $dir.'/'.$stamp.'.md';

            $body = "# Kraite trading incident — {$stamp}\n\n";
            $body .= "- detected_at: {$now->toDateTimeString()} (server time)\n";
            $body .= "- trigger: **{$reason}**\n";
            $body .= "- action_taken: cooled the bot (allow_opening_positions=false)\n";
            $body .= "- narrated: NO  <!-- the Haiku narrator flips this to YES -->\n\n";
            $body .= "## Evidence (deterministic)\n\n```json\n".json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n```\n\n";
            $body .= "## For the operator / terminal LLM\n\n";
            $body .= "The bot has stopped opening new positions. Existing positions keep trading and stay protected.\n";
            $body .= "Diagnose the trigger above, fix the root cause, then clear the cooldown:\n";
            $body .= "`allow_opening_positions=true` on the kraite singleton, and delete `monitoring/OPEN-INCIDENT`.\n\n";
            $body .= "## Narration (Haiku fills this in)\n\n_pending_\n";

            File::put($file, $body);
            File::put($dir.'/OPEN-INCIDENT', $stamp.'.md');

            $this->verboseComment("  Incident written: monitoring/{$stamp}.md");
        } catch (Throwable $e) {
            Log::error('[monitor-guard] failed to write incident file (cooldown still applied)', [
                'reason' => $reason,
                'message' => $e->getMessage(),
            ]);
        }
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
