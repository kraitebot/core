<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Recovery;

use Kraite\Core\Models\Account;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Math;
use StepDispatcher\Models\Step;
use StepDispatcher\States\NotRunnable;
use StepDispatcher\Support\Steps;
use Throwable;

/**
 * AccountRecoveryRunner
 *
 * Runs the full disaster-recovery reconciliation for ONE account —
 * Phases 1-4 — into a shared {@see RecoveryReport}. Single source of
 * per-account recovery used by BOTH the inline command loop and the
 * fleet-distributed `RecoverAccountPositionsJob`, so the two modes can
 * never drift.
 *
 *   Phase 1 — exchange → local: create / reconcile open positions + orders
 *   Phase 2 — close-detection: local opens not on the exchange → closed
 *   Phase 3 — order-status mirror: apiSync non-terminal orders
 *   Phase 4 — stuck-state reset: opening/syncing/cancelling → active/closed
 *
 * Every exchange API call inside Phase 1 routes through the per-IP
 * throttle gate (see `RecoveryApiThrottle` inside the recoverers). Phases
 * 2-4 issue at most one account-wide positions query, batched when the
 * caller injects it.
 */
final class AccountRecoveryRunner
{
    /**
     * @param  array<int, array<string, mixed>>|null  $batchedOpenOrders  account-wide open orders pre-fetched by the fleet job
     * @param  array<int, array<string, mixed>>|null  $batchedAlgoOrders  account-wide algo orders pre-fetched by the fleet job
     */
    public function __construct(
        private Account $account,
        private RecoveryReport $report,
        private ?string $tokenFilter = null,
        private bool $allowUntestedExchange = false,
        private ?array $batchedOpenOrders = null,
        private ?array $batchedAlgoOrders = null,
    ) {}

    /**
     * Run all four phases for this account. A health-check failure or an
     * untested-exchange gate records the skip and returns without touching
     * the DB. Phase-level throwables are contained so one bad phase can't
     * abort the rest of the account.
     */
    public function run(): void
    {
        $exchange = $this->account->apiSystem->canonical ?? 'unknown';
        $this->report->line("=== Account #{$this->account->id} ({$this->account->name}, {$exchange}) ===");

        if (! $this->healthCheck()) {
            $this->report->accountsSkipped++;

            return;
        }

        // Phase 1 — exchange → local.
        if (! $this->reconcileFromExchange($exchange)) {
            return;
        }

        $this->report->accountsOk++;

        // Phases 2-4 share one account-wide exchange snapshot.
        $exchangeKeys = $this->fetchExchangePositionKeys();

        $this->markClosedDuringGap($exchangeKeys);
        $this->mirrorOrderStatuses();
        $this->resetStuckStates($exchangeKeys);
    }

    /**
     * Phase 1. Resolve the per-exchange recoverer, gate untested exchanges,
     * inject any pre-fetched order batches, and run the reconstruction.
     * Returns false when the account was skipped (untested / aborted).
     */
    private function reconcileFromExchange(string $exchange): bool
    {
        try {
            $recoverer = RecovererResolver::for($this->account, $this->report, $this->tokenFilter);

            if ($recoverer->isUntested() && ! $this->allowUntestedExchange) {
                $this->report->accountsSkipped++;
                $this->report->warning(
                    "Account #{$this->account->id} ({$exchange}) skipped — recoverer flagged untested. Re-run with --allow-untested-exchange to permit."
                );
                $this->report->line('  ⚠ Recoverer is flagged UNTESTED for this exchange. Skipped (use --allow-untested-exchange to override).');

                return false;
            }

            $recoverer->withBatchedOrders($this->batchedOpenOrders, $this->batchedAlgoOrders);
            $recoverer->run();

            return true;
        } catch (Throwable $e) {
            $this->report->accountsSkipped++;
            $this->report->warning("Account #{$this->account->id} ({$exchange}) aborted: {$e->getMessage()}");
            $this->report->line("  ✗ Recoverer aborted: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Light authenticated round-trip via apiQueryBalance to prove the
     * credentials are valid before recovery touches the DB.
     */
    private function healthCheck(): bool
    {
        try {
            $response = RecoveryApiThrottle::call($this->account, fn () => $this->account->apiQueryBalance());

            if ($response->result === []) {
                $this->report->warning("Account #{$this->account->id}: empty balance response — credentials likely invalid");
                $this->report->line('  ✗ API health-check returned empty body');

                return false;
            }

            $this->report->line('  ✓ API credentials valid');

            return true;
        } catch (Throwable $e) {
            $this->report->warning("Account #{$this->account->id}: balance call failed — {$e->getMessage()}");
            $this->report->line("  ✗ API health-check failed: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * "SYMBOL:DIRECTION" keys from the exchange's current open-positions
     * snapshot. Empty array on API failure — callers refuse to mass-close
     * on an empty snapshot when local opens exist (transient-failure guard).
     *
     * @return array<int, string>
     */
    private function fetchExchangePositionKeys(): array
    {
        try {
            $response = RecoveryApiThrottle::call($this->account, fn () => $this->account->apiQueryPositions());
            $positions = collect($response->result ?? []);
        } catch (Throwable) {
            return [];
        }

        $keys = [];
        foreach ($positions as $key => $row) {
            $amt = (string) ($row['positionAmt']
                ?? $row['size']
                ?? $row['total']
                ?? $row['currentQty']
                ?? $row['contracts']
                ?? '0');

            if (Math::equal($amt, '0')) {
                continue;
            }

            [$symbol, $side] = array_pad(explode(':', (string) $key), 2, 'BOTH');

            if ($side === 'BOTH') {
                $side = Math::gt($amt, '0') ? 'LONG' : 'SHORT';
            }

            $keys[] = "{$symbol}:{$side}";
        }

        return $keys;
    }

    /**
     * Phase 2. Local opened-status positions whose (symbol, direction) key
     * isn't on the exchange closed during the gap → mark closed. Refuses to
     * act on an empty exchange snapshot when local opens exist.
     *
     * @param  array<int, string>  $exchangeKeys
     */
    private function markClosedDuringGap(array $exchangeKeys): void
    {
        $localOpenQuery = Position::query()
            ->where('account_id', $this->account->id)
            ->whereIn('status', (new Position)->openedStatuses());

        if ($this->tokenFilter !== null) {
            $localOpenQuery->where('parsed_trading_pair', mb_strtoupper($this->tokenFilter));
        }

        $localOpen = $localOpenQuery->get();

        if ($localOpen->isEmpty()) {
            return;
        }

        if ($exchangeKeys === []) {
            $this->report->line("  → Account #{$this->account->id}: exchange snapshot empty + {$localOpen->count()} local open position(s) — skipping close-detection (treat as transient API failure)");

            return;
        }

        foreach ($localOpen as $position) {
            $key = "{$position->parsed_trading_pair}:{$position->direction}";

            if (in_array($key, $exchangeKeys, true)) {
                continue;
            }

            $position->updateSaving([
                'status' => 'closed',
                'closed_at' => now(),
                'error_message' => 'Closed during disaster-recovery gap; exchange snapshot at T-recovery did not contain this position',
            ]);

            $this->report->line("  ✓ Position #{$position->id} ({$position->parsed_trading_pair} {$position->direction}) marked closed (not on exchange)");
        }
    }

    /**
     * Phase 3. apiSync every non-terminal order on still-active positions so
     * CANCELLED/FILLED/EXPIRED drift from the gap is reflected locally.
     * Observer suppressed so a status flip doesn't dispatch Close/Wap jobs
     * against the half-recovered DB.
     */
    private function mirrorOrderStatuses(): void
    {
        $orders = Order::query()
            ->whereIn('status', ['NEW', 'PARTIALLY_FILLED'])
            ->whereNotNull('exchange_order_id')
            ->whereHas('position', function ($q): void {
                $q->where('account_id', $this->account->id)
                    ->whereIn('status', (new Position)->openedStatuses());

                if ($this->tokenFilter !== null) {
                    $q->where('parsed_trading_pair', mb_strtoupper($this->tokenFilter));
                }
            })
            ->get();

        foreach ($orders as $order) {
            try {
                Order::withoutEvents(fn () => RecoveryApiThrottle::call($this->account, fn () => $order->apiSync()));
            } catch (Throwable $exception) {
                $this->report->warning("Account #{$this->account->id}: order #{$order->id} apiSync failed — {$exception->getMessage()}");
            }
        }
    }

    /**
     * Phase 4. Positions stuck in opening/syncing/cancelling with no
     * in-flight workflow get reset from exchange truth: on the exchange →
     * active; not on it → closed. Refuses to ghost-close on an empty
     * snapshot; defers positions a live workflow still owns.
     *
     * @param  array<int, string>  $exchangeKeys
     */
    private function resetStuckStates(array $exchangeKeys): void
    {
        $stuckStatuses = ['opening', 'syncing', 'cancelling'];

        $stuckQuery = Position::query()
            ->where('account_id', $this->account->id)
            ->whereIn('status', $stuckStatuses);

        if ($this->tokenFilter !== null) {
            $stuckQuery->where('parsed_trading_pair', mb_strtoupper($this->tokenFilter));
        }

        $stuck = $stuckQuery->get();

        if ($stuck->isEmpty()) {
            return;
        }

        if ($exchangeKeys === []) {
            $this->report->warning(
                "Account #{$this->account->id}: empty exchange snapshot but {$stuck->count()} stuck position(s) — refusing to ghost-close (likely API failure)"
            );
            $this->report->line("  ⚠ Account #{$this->account->id}: skipped Phase 4 — empty exchange snapshot, {$stuck->count()} stuck position(s) preserved");

            return;
        }

        foreach ($stuck as $position) {
            if ($this->hasInflightStepFor($position->id)) {
                $this->report->line("  ⚠ Position #{$position->id} ({$position->parsed_trading_pair} {$position->direction}): kept in {$position->status} (in-flight workflow still owns it)");

                continue;
            }

            $key = "{$position->parsed_trading_pair}:{$position->direction}";

            if (in_array($key, $exchangeKeys, true)) {
                $position->updateSaving(['status' => 'active']);
                $this->report->line("  ✓ Position #{$position->id} ({$position->parsed_trading_pair} {$position->direction}): {$position->getOriginal('status')} → active (exchange has it)");

                continue;
            }

            $position->updateSaving([
                'status' => 'closed',
                'closed_at' => now(),
                'error_message' => "Closed during disaster-recovery (stuck in {$position->getOriginal('status')} with no exchange-side position)",
            ]);
            $this->report->line("  ✓ Position #{$position->id} ({$position->parsed_trading_pair} {$position->direction}): {$position->getOriginal('status')} → closed (exchange has nothing)");
        }
    }

    /**
     * True when a non-terminal Step in EITHER prefix references this
     * position via JSON arguments. NotRunnable rescue branches are excluded
     * — a healthy open position always leaves one parked, and counting it
     * would defer recovery forever.
     */
    private function hasInflightStepFor(int $positionId): bool
    {
        $check = function () use ($positionId): bool {
            return Step::query()
                ->whereNotIn('state', array_merge(Step::terminalStepStates(), [NotRunnable::class]))
                ->whereRaw(
                    "CAST(JSON_EXTRACT(arguments, '$.positionId') AS UNSIGNED) = ?",
                    [$positionId],
                )
                ->exists();
        };

        if ($check()) {
            return true;
        }

        return (bool) Steps::usingPrefix('trading', $check);
    }
}
