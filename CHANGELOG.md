# Changelog

All notable changes to this project will be documented in this file.

## 1.4.8 - 2026-04-23

### Fixes

- [BUG FIX] `Jobs/Atomic/Position/VerifyOrderNotionalForMarketOrderJob::computeApiable()` — simulate the full DCA limit ladder via `Kraite::calculateLimitOrdersData()` BEFORE `PlaceMarketOrderJob` runs. USELESS #64 incident: market entry filled, then rung #1 notional came in at $3.71 on `[2,2,2,2]` multipliers against `min_notional=5`, cascading into the cancel workflow which closed the orphaned entry at a realized loss. Detecting the infeasible ladder up-front aborts the workflow before any exchange-side state exists to unwind.
- [BUG FIX] `Models/Order::removeTrailingZeros()` — stopped round-tripping through `(float)` inside the price/quantity accessors. IEEE-754 exposed residues on large integer quantities (`692672.00000000` surfaced as `692672.0000000001`), which leaked into the UI as a phantom drift between our DB and the exchange. Now a pure string-level trim.

### Features

- [NEW FEATURE] `Concerns/Position/HasStatuses::updateToFailed()` — on the transition into `failed` (guarded so re-entries are idempotent), fires the new `position_opening_failed` canonical via `NotificationService` AND flips `exchange_symbol.is_manually_enabled=false` so the opening scheduler stops picking the same broken token. Notification carries token/pair/direction/position id/reason/whether the token was blocked.
- [NEW FEATURE] New canonical `position_opening_failed` seeded in the `notifications` table (priority 1 / high) with a multi-line Pushover template rendered in `NotificationMessageBuilder`.
- [NEW FEATURE] New command `kraite:disable-volatile-tokens` (`Commands/Cronjobs/DisableVolatileTokensCommand`) — sweeps `exchange_symbols` across every api_system and flips `is_manually_enabled=false` on any row whose token is on the curated deny-list. Three class-level constants split by reason (MEMES / SPECULATIVE / STRUCTURAL-BRITTLE). Strictly additive — never re-enables. Supports `--dry-run` and `--output`. Wired via `CoreServiceProvider` and scheduled hourly at `:45` in `routes/console.php`.

### Improvements

- [IMPROVED] `Concerns/Order/InteractsWithApis::resolveSyncedPrice()` — positive incoming prices now pass through `api_format_price()` so the DB value is tick-aligned against the symbol's current precision, matching the contract enforced on the outbound (place/modify) path. Zero/null fallback behaviour preserved.
- [IMPROVED] New sibling helper `Concerns/Order/InteractsWithApis::resolveSyncedQuantity()` — same contract for quantity via `api_format_quantity()`. Preserves the DB value when the exchange omits the field, honours legitimate zero (Binance algo `executedQty` for unfilled STOP-MARKET with `closePosition=true`), and lot-aligns otherwise.
- [IMPROVED] All four sync sites — `apiSyncDefault`, `apiSyncAlgo`, `apiSyncStopOrder`, `apiSyncPlanOrder` — now route the incoming quantity through `resolveSyncedQuantity()` instead of writing the exchange's raw value unchecked.

## 1.4.7 - 2026-04-23

### Fixes

- [BUG FIX] `Jobs/Atomic/Order/CalculateWapAndModifyProfitOrderJob` — removed `final` from the base class. Exchange-specific override `Binance\CalculateWapAndModifyProfitOrderJob` extends it; with `final` in place, JobProxy's `class_exists` check blew up with a `FatalError: cannot extend final class` mid-tick, killing the WAP chain after step 3 and leaving positions wedged in `waping`.
- [BUG FIX] `Jobs/Atomic/Order/CalculateWapAndModifyProfitOrderJob::computeApiable()` — short-circuit ignorable Binance responses. `apiModify` can legitimately return `400 -5027 "No need to modify the order"` on the follow-up WAP pass (values already match exchange state); we were wrapping the ClientException in a RuntimeException, which hid the vendor code from the framework's ignore-classifier and marked the step Failed. Now detects ignorable exceptions via `externalIgnoreException()` and falls through to the normal success path so `complete()` runs and reference values / position quantity / was_waped flags are all updated correctly.
- [BUG FIX] `Jobs/Atomic/Order/RecreateCancelledOrderJob::startOrFail()` — new `isCloseAllAlgoOrder()` helper exempts closePosition-style algo orders (is_algo + reference_quantity=0) from the remaining-quantity gate. Previously, Binance STOP-MARKET orders with `closePosition=true` (valid `quantity=0`) were rejected as "nothing to recreate" and the SmartReplace chain cascaded to Failed.
- [BUG FIX] `Jobs/Lifecycles/Position/ApplyWapJob` — widened the `UpdatePositionStatusJob → waping` guard from `onlyFromStatus='active'` to `['active', 'syncing']`. LIMIT fills detected mid-sync race against sync's own `complete()` flip-back; blocking `syncing` here left the WAP sub-chain running against stale status and downstream `CalculateWap::startOrFail` (requires `waping`) to fail with no recovery.

### Features

- [NEW FEATURE] New command `kraite:purge-model-logs --duration=X` — chunked deletes of `model_logs` rows older than X days (default 30), used to keep the per-model attribute-change audit table bounded over time. Wired to the scheduler daily at 03:30.
- [NEW FEATURE] `Jobs/Atomic/Order/CancelOrphanAlgoOrdersJob` (base) + `Jobs/Atomic/Order/Binance/CancelOrphanAlgoOrdersJob` (exchange-specific) — scrub unknown algo orders on a symbol before recreating our reference SL/TP. Binance's UI "Edit" on an algo order cancels the original and places a new algoId we've never seen; without this scrub, `SmartReplaceOrdersJob` would leave the user's ghost order live alongside our recreation. Wired as step 1 of `SmartReplaceOrdersJob::compute()` via `JobProxy`.
- [NEW FEATURE] `ApiExceptionHelpers::containsHttpExceptionIn()` now walks the `$exception->getPrevious()` chain (up to 10 levels) so wrapped `RuntimeException`s carrying a Guzzle `ClientException` underneath still route to the correct ignore/retry/recv-window/forbidden classifier. Prior to this, any atomic that wrapped a Guzzle exception for richer diagnostics lost its vendor-code classification.
- [NEW FEATURE] Six trading-lifecycle notification canonicals seeded in `notifications` table with `NotificationMessageBuilder::build()` templates: `position_opened`, `position_closed`, `position_wap_applied`, `position_high_profit_closed`, `position_pump_cooldown_triggered`, `position_residual_detected`. Each ships multi-line Pushover content (direction, pair, pos id, price/qty deltas, break-even, etc.) with per-canonical Pushover priority (low for informational, high for WAP + pump-cooldown, emergency for residual).
- [NEW FEATURE] `NotificationMessageBuilder` return shape now supports an optional `priority` key (int -2..2) that overrides `AlertNotification`'s default severity-based mapping. Let trading events pick specific Pushover device behaviour — e.g. Info severity delivered silently at priority -1, or a High-severity WAP event delivered at priority 1 to bypass quiet hours.
- [NEW FEATURE] `NotificationService::send()` reads the optional `priority` key and forwards it through `additionalParameters['priority']` to `AlertNotification`.

### Improvements

- [IMPROVED] Three ad-hoc `$user->notify(new AlertNotification(...))` call sites unified onto `NotificationService::send()` with proper canonicals + cache-throttling + `notification_logs` entries: `UpdateRemainingClosingDataJob::dispatchClosedNotification()` + `dispatchHighProfitNotification()`, `ClosePositionAtomicallyJob::notifyPumpCooldown()`, `VerifyPositionResidualAmountJob::notifyResidualPosition()`. All three now share the pipeline used by every other canonical in the system.
- [IMPROVED] `ActivatePositionJob::complete()` + `UpdateRemainingClosingDataJob::computeApiable()` + `CalculateWapAndModifyProfitOrderJob::complete()` now dispatch the new trading-lifecycle notifications to the position's account owner (falling back to admin when null). Recipients receive a clear audit trail on their phone without blocking on terminal log-diving.
- [IMPROVED] `Jobs/Atomic/Position/UpdatePositionStatusJob::$onlyFromStatus` widened from `?string` to `string|array|null`. Single-status strings still work unchanged; arrays let callers specify multiple acceptable prior statuses (used by `ApplyWapJob` to accept both `active` and `syncing` for the initial WAP flip).

## 1.4.6 - 2026-04-23

### Fixes

- [BUG FIX] `Jobs/Lifecycles/Account/PreparePositionsOpeningJob` — dropped the spurious `child_block_uuid` on the `DispatchPositionSlotsJob` step. The slots-job spawns each position in its own isolated block (intentional fan-out design), so declaring a child block that never receives children left the parent step wedged in `Running` forever and blocked `PreparePositionsOpeningJob` from ever completing.
- [BUG FIX] `Observers/OrderObserver::updating()` — guarded `filled_at` assignment behind `status === 'FILLED' && filled_at === null`. Without the null guard, every subsequent save that kept `status=FILLED` (e.g. the close workflow's re-sync) overwrote the original fill timestamp with `now()`, losing the real moment Binance reported the fill.
- [BUG FIX] `Concerns/Order/InteractsWithApis::resolveSyncedPrice()` + all four `apiSync*` methods — preserve the stored DB price when the exchange echoes `null` / `''` / `0` on sync. Cancelled Binance algo orders respond with `price=0` on the cancelled record; the old code blindly wrote that `0` into the DB and destroyed the STOP-MARKET trigger-price audit trail.
- [BUG FIX] `Jobs/Atomic/Position/UpdateRemainingClosingDataJob::computeApiable()` — removed the `profitOrder()` gate on the trade-query path. `profitOrder()` excludes CANCELLED/EXPIRED, so a manual close (which leaves the TP `EXPIRED` on Binance) returned null and the whole closing-price extraction was skipped. Also removed the dead `$orderId = $this->profitOrder()->exchange_order_id;` line in `Concerns/Position/InteractsWithApis::apiQueryTokenTrades()` that threw warnings when `profitOrder()` was null.
- [BUG FIX] `Jobs/Atomic/Order/SyncPositionOrdersJob` — the "all failed" exception message was interpolating a failed order's `id` into the count slot (`"All {$failedOrders[0]['id']}+ orders failed..."`) instead of `count($failedOrders)`. Logs read like `"All 6575995185+ orders failed..."` on a single-order failure — now correctly reports `"All N orders failed..."`.

### Features

- [NEW FEATURE] `Models/ModelLog::setCurrentStep(?Step)` / `currentStep(): ?Step` — process-scoped context tracker so attribute-change audit rows carry causality. `BaseQueueableJob::prepareJobExecution()` sets it when a step-backed job starts; `Queue::after` / `Queue::failing` listeners (registered in `CoreServiceProvider::boot()`) clear it for queued paths; a `__destruct` on `BaseQueueableJob` covers synchronous / test execution paths. `ModelLogObserver::created()` / `saved()` stamp `relatable_type` / `relatable_id` from the current step — those two columns used to be 100% NULL on attribute-change rows.
- [NEW FEATURE] `Support/Math::isPositive(mixed $value, ?int $scale = null): bool` — null-safe "strictly positive" predicate. Accepts mixed (non-numeric types return false), delegates empty/whitespace/sign-only handling to `toDecimalString` ("0"), catches `InvalidArgumentException` from malformed numeric strings. Used by `resolveSyncedPrice()` and `UpdateRemainingClosingDataJob::extractClosingPriceFromTrades()` so both sites collapse to one-liners instead of duplicated null+empty+Math::gt/lte guards.
- [NEW FEATURE] `Jobs/Atomic/Position/UpdateRemainingClosingDataJob::extractClosingPriceFromTrades(array $trades, string $direction): ?string` — picks the closing trade's price from a user-trades response. Reducing-side (`SELL` for LONG, `BUY` for SHORT) with optional `positionSide` match for hedge-mode exchanges; scans newest-first and short-circuits on first match, with a "last trade" fallback when positionSide is absent.

### Improvements

- [IMPROVED] `Jobs/Atomic/Order/SyncPositionOrdersJob` + `Jobs/Lifecycles/Order/PrepareSyncOrdersJob` — split-ownership refactor for the `active ↔ syncing` transitions. `PrepareSyncOrdersJob` no longer flips to `syncing` (previously left the position wedged if the atomic child never reached `computeApiable`, e.g. `startOrFail` → `NonNotifiableException`, a dispatcher crash, or a worker OOM). `SyncPositionOrdersJob::computeApiable()` now owns `active → syncing` at its top, and the framework's `complete()` hook owns `syncing → active` on success — exception / retry / ignore paths intentionally leave the position in `syncing` so a wedged sync surfaces instead of silently rolling back. The `complete()` gate on `status === 'syncing'` guarantees observer-dispatched workflows (Close / Wap / Replace) that claimed the position mid-compute are never overwritten.
- [IMPROVED] `Support/ApiDataMappers/Binance/ApiRequests/MapsAccountQueryTrades::prepareQueryTokenTradesProperties()` — added `options.limit = '5'`. Binance's `/fapi/v1/userTrades` defaults to returning up to 500 trades; the closing-price extractor scans newest-first and short-circuits, so the cap cuts response payload ~99% with zero behavioural change. `Support/Apis/REST/BinanceApi::accountTrades()` validator now whitelists `options.limit`.

## 1.4.5 - 2026-04-22

### Features

- [NEW FEATURE] `Jobs\Models\ExchangeSymbol\DispatchPerSymbolKlineBlocksJob` — orchestrator that fires one independent block per exchange symbol after the shared BTC baseline klines have landed. Each per-symbol block holds `FetchKlinesJob` at index 1 and `CalculateBtcCorrelationJob`+`CalculateBtcElasticityJob` at index 2, so correlation for a symbol runs the moment its own klines complete rather than waiting for every other symbol in the batch.

### Improvements

- [IMPROVED] `FetchKlinesCommand` bulk + active-positions paths — replaced the single shared block (one `blockUuid` for the whole batch) with a two-phase layout: a shared block containing only the BTC baseline klines at index 1 and the new `DispatchPerSymbolKlineBlocksJob` at index 2. Once BTC klines are in the DB, the orchestrator spawns per-symbol sub-blocks in parallel. Fixes the previous behaviour where one slow/stuck kline blocked every correlation in the batch.

## 1.4.4 - 2026-04-22

### Improvements

- [IMPROVED] Dispatcher-health monitoring moved out of Kraite and into `brunocfalcao/step-dispatcher` (v1.11+). The old `kraite:cron-check-stale-data` command is removed; stall detection (Running zombies, stuck Dispatched steps, wedged dispatcher locks) now lives in the package as `steps:recover-stale --recover-dispatched --release-locks`. Kraite only owns the notification translation now — `SendStaleStepsNotification` listens for `StepDispatcher\Events\StaleStepsDetected` and maps it to the existing `stale_dispatched_steps_detected` / `stale_priority_steps_detected` canonicals, so operators see the same pushover/email output they did before.

### Breaking

- [IMPROVED] Removed command `kraite:cron-check-stale-data` and class `Kraite\Core\Commands\Cronjobs\CheckStaleDataCommand`. Host apps that had it scheduled must swap to `steps:recover-stale --recover-dispatched --release-locks` on the same cadence. Notification canonicals and templates are unchanged.

## 1.4.3 - 2026-04-22

### Features

- [NEW FEATURE] `Account::apiQuerySymbolConfig()` — cross-exchange method returning per-symbol `leverage + marginType` keyed by symbol. Binance hits the dedicated `/fapi/v1/symbolConfig` endpoint (v3 positionRisk stopped returning those fields). Bitget/Bybit/Kucoin reuse their positions payload and normalize to the same shape. Backed by new `MapsSymbolConfigQuery` traits + `BinanceApi::getSymbolConfig()` (REST method per exchange).

### Improvements

- [IMPROVED] Multi-queue routing — every `Step::create([...])` call site across the package now sets an explicit `'queue' => '<domain>'` based on the target job's constructor primary parameter: `positions` (positionId), `orders` (orderId), `indicators` (exchangeSymbolId / apiSystemId-indicator work), `cronjobs` (accountId / no-entity cron orchestrators). Removes the old global-default fallback so any unmapped step surfaces in the audit instead of silently routing to `default`.
- [IMPROVED] `CalculateBtcCorrelationJob` — replaced the O(n²) `Collection::firstWhere` pairing loop with an O(1) array lookup keyed by timestamp. BTC candles are now cached per `(exchange_symbol, timeframe, window)` for 30 s so one query serves the whole correlation batch instead of ~3,500 duplicate reads per tick.
- [IMPROVED] `PrepareSyncOrdersJob::compute()` — temporary instrumentation logging per-block timings (`refresh_ms`, `update_to_syncing_ms`, `resolve_lifecycle_ms`, `dispatch_child_ms`, `total_ms`) to `Log::channel('jobs')`. Confirmed compute body runs in ~20 ms; the residual 1-5 s wall-clock lives in the step-dispatcher state transition machinery around the job, not inside it.

## 1.4.2 - 2026-04-21

### Fixes

- [BUG FIX] Reverted the atomic-throttler rewrite (v1.5.0 / v1.5.1). The rewrite correctly eliminated 429s but introduced head-of-line blocking: 20 Horizon workers would all pick up CMC steps, each hold the per-API lock + `usleep()` for the full 2500 ms min-delay, leaving no free workers to process the 1,400+ non-CMC steps sitting in Dispatched. Reverted to the pre-rewrite `canDispatch` / `recordDispatch` behaviour — known to produce ~33% 429s on CMC but keeps workers productive. A real fix (atomic reservation WITHOUT inline sleep, returning positive wait seconds when min-delay isn't satisfied) is parked until we can validate it properly.
- [BUG FIX] Kept the removal of the duplicate `Step::observe(StepObserver::class)` from `CoreServiceProvider::boot()`. The step-dispatcher package already registers its own observer; the duplicate here was causing every state-transition log line to fire twice. The revert of 1.5.0 accidentally restored it — removed again so the diagnostic logs stay clean when `STEP_DISPATCHER_LOGGING_ENABLED=true`.

## 1.4.1 - 2026-04-21

### Improvements

- [IMPROVED] `BaseApiableJob::shouldStartOrThrottle` now writes per-step diagnostic lines to the `throttled.log` channel whenever a throttle or pre-flight check returns a wait > 0. Distinguishes between `ExceptionHandler::isSafeToMakeRequest`, `Throttler::isSafeToDispatch`, and `Throttler::canDispatch` so we can tell exchange-specific min-delay / IP-ban gating apart from base-class window/requests-per-window gating. Gated by the same `STEP_DISPATCHER_LOGGING_ENABLED` env flag — no writes in production unless debugging is on.

## 1.4.0 - 2026-04-21

### Improvements

- [IMPROVED] `Position::profitOrder()` — now excludes `CANCELLED` and `EXPIRED` rows so the correction workflow's cancel-and-recreate window (old row CANCELLED, new row not yet created) never returns a dead order to callers like `VerifyIfTPIsFilledJob` or `CalculateWapAndModifyProfitOrderJob`. `FILLED` is kept intentionally — `VerifyIfTPIsFilledJob` needs it to detect a just-filled TP.
- [IMPROVED] `UpdatePositionStatusJob` — added optional `onlyFromStatus` guard. When provided, the transition only fires if the current status matches. Prevents WAP's revert-to-active from clobbering a concurrent close workflow that has already moved the position to `closing`.
- [IMPROVED] `ApplyWapJob` — step 1 (→waping) guarded `onlyFromStatus='active'`; step 5 and resolve-exception (→active) guarded `onlyFromStatus='waping'`. The lifecycle no longer stomps over in-flight close workflows when a TP fill slips past `VerifyIfTPIsFilledJob`.
- [IMPROVED] `OrderObserver::checkForOrderModification` — skips drift detection while the position is in a transitional state (`opening`, `waping`, `syncing`). Our own workflows intentionally mutate order price/quantity during those states; evaluating drift there was false-firing `PrepareOrderCorrectionJob` against our own in-flight work.
- [IMPROVED] `CalculateWapAndModifyProfitOrderJob` (Bitget variant) — added consistency gate, `intendedPrice`/`intendedQty` tracking, and try/catch around `apiModifyTpsl` with diagnostic `apiSync` on failure. Brings Bitget to parity with the Binance variant hardened in 1.3.9. Also converts "position closed externally" paths to `NonNotifiableException` so resolve-exception runs quietly.
- [IMPROVED] `CalculateWapAndModifyProfitOrderJob` (base) — "position not in snapshot" and "zero exchange quantity" now throw `NonNotifiableException` so close/sync workflows reconcile without pushover noise.

## 1.3.9 - 2026-04-21

### Fixes

- [BUG FIX] `OrderObserver::dispatchApplyWap` — `reference_status = FILLED` bump moved to AFTER the dedup check. When dedup skipped the dispatch (another WAP was already pending), ack was happening anyway, silencing the observer for that fill permanently. Now the fill stays unacked and is picked up by a follow-up WAP.
- [BUG FIX] `CalculateWapAndModifyProfitOrderJob::complete()` — after a successful WAP, scans for LIMIT orders with `status=FILLED` but `reference_status != FILLED` and self-dispatches a follow-up `ApplyWapJob` (3s delay). Covers the case where a DCA fill arrived during the prior WAP and was skipped by observer dedup; without this, that fill's quantity never made it into the TP.
- [BUG FIX] `CalculateWapAndModifyProfitOrderJob::computeApiable()` — consistency gate: if the exchange's `positionAmt` is less than the local sum of MARKET+FILLED LIMIT quantities, the matching engine has not yet committed the triggering fill into `breakEvenPrice`. Throws with a clear message so the step retries with a fresh snapshot instead of silently computing WAP against stale breakeven.
- [BUG FIX] `CalculateWapAndModifyProfitOrderJob::doubleCheck()` — now verifies the profit order's price (within one tick) and quantity (exact) actually match what the job intended to send, not just that status is NEW/PARTIALLY_FILLED. Catches silent no-op modifies.
- [BUG FIX] `CalculateWapAndModifyProfitOrderJob::computeApiable()` — `apiModify()` wrapped in try/catch. On any exception, runs `apiSync` to capture the real exchange state and rethrows a descriptive `RuntimeException` with intended vs actual price/qty/status. Replaces the opaque "WAP failed" error with actionable diagnostics.

## 1.3.8 - 2026-04-21

### Fixes

- [BUG FIX] `PreparePositionsOpeningJob::compute()` now bails out with a no-op if any child step already exists in its block. Prevents duplicate Position rows when the orchestrator's `compute()` re-executes after a retry (e.g. recover-stale flipping the parent Running → Pending).
- [BUG FIX] `ClosePositionAtomicallyJob` — catches Binance `-2022` ("ReduceOnly Order is rejected") and rethrows as `NonNotifiableException` with a clear operator-facing message flagging potential orphan exposure on the exchange. No silent retry of an order that cannot succeed.
- [BUG FIX] `InteractsWithApis::apiSyncDefault()` — when Bybit/KuCoin return `status = 'NOT_FOUND'` (order no longer in active list), the value is logged and the DB row is left untouched instead of polluting `orders.status` with the literal string.
- [BUG FIX] `PrepareSyncOrdersJob::compute()` only flips the position to `syncing` when its current status is exactly `active` — prevents race with in-flight open/close/cancel workflows that own the position's status.
- [BUG FIX] `OrderObserver::dispatchClosePosition()` deduplicates pending `ClosePositionJob` steps (mirroring the existing guards on replacement + WAP dispatches). Blocks double-close when TP and SL both reach FILLED in the same sync cycle.
- [BUG FIX] `UpdateRemainingClosingDataJob` — replaces a per-order `updateSaving` loop with a single transactional `UPDATE ... reference_status = status`, avoiding half-updated state on mid-loop failure.
- [BUG FIX] `SyncPositionOrdersJob` — if every order in the batch fails to sync (e.g. rate limit across the board), the job now throws to trigger retry escalation instead of silently continuing.

### Security

- [SECURITY] `HasOrderCalculations::calculateLimitOrdersData()` now rejects the ladder entirely if any rung's notional would fall below `exchange_symbol.min_notional`. Prevents placing sub-minimum orders that would be rejected mid-ladder and orphan a freshly-placed MARKET entry (root cause of one failed BAS position we observed with corrupt `limit_quantity_multipliers`).

## 1.3.7 - 2026-04-20

### Fixes

- [BUG FIX] `PlaceMarketOrderJob` retry idempotency — `startOrFail()` now restores the existing `marketOrder` from DB on retry, and `computeApiable()` early-returns when the order already has an `exchange_order_id`. Previously, a retry could place a duplicate market order on the exchange (no `exchange_order_id` guard like `PlaceLimitOrderJob` has).

### Security

- [SECURITY] New migration adds a partial unique index `ux_positions_open_slot` on `(account_id, exchange_symbol_id, direction)` for non-terminal positions, using a `VIRTUAL` generated `is_open` column so NULL rows (closed/cancelled/failed) are exempt. DB-level guard against duplicate open positions regardless of application-layer races.

## 1.3.6 - 2026-04-20

### Fixes

- [BUG FIX] `DiscoverCMCTokenForExchangeSymbolJob` — renamed `startOrFail` hook to `startOrSkip` so symbols already linked by a parallel job transition to `Skipped` state instead of `Failed`. Previously, the idempotent "already linked" branch was incorrectly routed through the failure path, producing misleading errors.

### Improvements

- [IMPROVED] Default exchange timeframes trimmed from `[5m, 1h, 4h, 12h, 1d]` to `[1h, 4h, 12h]` in `KraiteSeeder` and `ApiSystemFactory` — removes noisy 5m signals and redundant 1d anchor to reduce TAAPI load per indicator-refresh cycle.

## 1.3.5 - 2026-04-11

### Features

- [NEW FEATURE] `CheckKLinesForCorrelationJob` — checks candle availability for BTC correlation, spawns child FetchKlines steps if missing

### Improvements

- [IMPROVED] `ConcludeSymbolDirectionAtTimeframeJob` now creates BTC correlation steps (INDEX 5-6) when `kraite.correlation.enabled` is true
- [IMPROVED] Correlation workflow: CheckKLines → CalculateBtcCorrelation → CalculateBtcElasticity runs after direction finalization

## 1.3.4 - 2026-04-06

### Improvements

- [IMPROVED] Rename `Engine` model to `Kraite` (`Models\Kraite`, `Trading\Kraite`, `Concerns\Kraite\`)
- [IMPROVED] Rename `martingalian` table to `kraite` via migration
- [IMPROVED] Update all imports, static calls, FQCN references, and aliases across entire codebase (~70 files)

## 1.3.3 - 2026-04-05

### Features

- [NEW FEATURE] Exchange cooldown mechanism — `cooldown_until` column on `api_systems`, triggered by server instability (503/504)
- [NEW FEATURE] `$serverInstabilityHttpCodes` on all exchange exception handlers with `shouldTriggerCooldown()` classification
- [NEW FEATURE] `HasCooldown` trait on ApiSystem — `inCooldown()` / `activateCooldown()` with configurable sliding window
- [NEW FEATURE] `StoreAccountBalanceJob` (Atomic) — queries account balance via exchange API with full exception handling
- [NEW FEATURE] `DispatchAccountBalancesJob` (Lifecycle) — orchestrates parallel balance queries for all active accounts

### Improvements

- [IMPROVED] `ApiRequestLogObserver` triggers exchange cooldown on server instability detection
- [IMPROVED] `CreatePositionsCommand` checks `inCooldown()` per exchange before dispatching
- [IMPROVED] `StoreAccountsBalancesCommand` refactored from direct API calls to step-based workflow
- [IMPROVED] Bybit exception handler: override `extractVendorCodeFromResponse()` for `retCode` format
- [IMPROVED] Add `cooldown_duration_minutes` config key (default 30 minutes)
- [IMPROVED] Simplify KraiteSeeder servers to single full-stack entry

## 1.3.2 - 2026-02-21

### Features

- [NEW FEATURE] Add `apiSystem()` BelongsTo relationship on ApiRequestLog model

### Improvements

- [IMPROVED] Add explicit return types and imports on ApiRequestLog relationships (MorphTo, BelongsTo)
- [IMPROVED] Rename `startOrFail()` to `startOrSkip()` in TouchTaapiDataForExchangeSymbolJob to prevent false failures
- [IMPROVED] Add missing `cleanLogsFolder()` helper to helpers.php

## 1.3.1 - 2026-02-21

### Improvements

- [IMPROVED] Add admin user and server secret config keys to `kraite.php` — eliminates all `env()` calls from KraiteSeeder
- [IMPROVED] Use `config('kraite.api.credentials.*')` for engine credential seeding

## 1.3.0 - 2026-02-21

### Improvements

- [IMPROVED] Split KraiteSeeder — remove business methods (traders, accounts, exchange integrations) to keep core as system-only seeder
- [IMPROVED] Replace `env()` calls with `config()` in seeder methods

## 1.2.9 - 2026-02-21

### Improvements

- [IMPROVED] Move 18 artisan commands from ingestion app to kraitebot/core — commands now available across all Kraite apps (admin, ingestion, kraite.com)
- [IMPROVED] Clean up CoreServiceProvider imports and formatting

## 1.2.8 - 2026-02-21

### Features

- [NEW FEATURE] Load shared `.env.kraite` file via CoreServiceProvider for unified environment across all Kraite apps

## 1.2.7 - 2026-02-21

### Improvements

- [IMPROVED] Consolidate `waitlist_subscribers` migration into kraitebot/core — single source of truth for all database schema

## 1.2.6 - 2026-02-21

### Features

- [NEW FEATURE] Add AppLog model — user-facing business activity logs with polymorphic relationships, immutable entries, and global enable/disable toggle
- [NEW FEATURE] Add `appLog()` method to BaseModel via LogsApplicationEvents trait for human-readable timeline logging
- [NEW FEATURE] Add `app_logs` migration with composite index on (loggable_type, loggable_id, created_at)
- [NEW FEATURE] Log business milestones across position creation chain: balance_verified, market_order_placed, limit_orders_dispatched, profit_order_placed, stop_loss_placed, position_activated, order_rejected

## 1.2.5 - 2026-02-21

### Fixes

- [BUG FIX] Fix Binance algo order query endpoint — use `GET /fapi/v1/openAlgoOrders` instead of non-existent `GET /fapi/v1/algoOrder`, which caused 400 errors during doubleCheck and cascading STOP-MARKET retry failures
- [BUG FIX] Update `resolveAlgoOrderQueryResponse` to handle array response format from `openAlgoOrders` endpoint

### Improvements

- [IMPROVED] OrderObserver now returns `false` from `creating()` to silently cancel duplicate orders instead of throwing `NonNotifiableException` — clean separation of concerns from StepDispatcher
- [IMPROVED] Rename observer helper methods to positive intent: `allowIfNoActiveExists`, `allowIfNoActiveProfitExists`, `allowIfLimitNotExceeded`
- [IMPROVED] DispatchLimitOrdersJob filters out observer-rejected orders via `->filter()->values()`
- [IMPROVED] Replace `JustEndException` and `JustResolveException` with standard `Exception` in VerifyMinAccountBalanceJob and ActivatePositionJob — these marker exceptions were functionally identical to regular exceptions

## 1.2.4 - 2026-02-19

### Improvements

- [IMPROVED] Remove deprecated `src/_Jobs` legacy namespace tree after migration to `Kraite\\Core\\Jobs\\...` classes

## 1.2.3 - 2026-02-19

### Fixes

- [BUG FIX] Replace float-cast based order formatting helpers with BCMath-safe decimal normalization for quantity and price formatting
- [BUG FIX] Use `Kraite\\Core\\Jobs\\Models\\ExchangeSymbol\\ConcludeSymbolDirectionAtTimeframeJob` in alignment confirmation previous-step lookup

## 1.2.2 - 2026-02-19

### Fixes

- [BUG FIX] Fix EMA indicator period parsing in `QuerySymbolIndicatorsJob` to read the period from the current TAAPI bulk ID format (`period_results_backtrack`)

## 1.2.1 - 2026-02-17

### Fixes

- [BUG FIX] Fix UpsertExchangeSymbolsFromExchangeJob hanging with Horizon concurrent workers — wrap upsert loop in DB::transaction() to eliminate observer overhead and table lock contention (70% performance improvement)

## 1.2.0 - 2026-02-14

### Improvements

- [IMPROVED] Extract generic step orchestration into `brunocfalcao/step-dispatcher`'s `BaseStepJob` — `BaseQueueableJob` now extends it with Kraite-specific defaults and hooks
- [IMPROVED] `BaseApiableJob` now uses hook methods (`externalRetryException`, `externalIgnoreException`, `externalResolveException`) to wire API exception handler
- [IMPROVED] Remove `BaseJob` class (absorbed into `BaseStepJob`), 3 traits, 3 exceptions, `ExceptionParser`, and database exception handling (all moved to step-dispatcher)

## 1.1.0 - 2026-02-13

### Security

- [SECURITY] Re-enable Zeptomail webhook signature verification (was bypassed with TODO early-return)
- [SECURITY] Remove logging of expected HMAC signatures in verification method (credential leak risk)
- [SECURITY] Add Pushover receipt format validation (alphanumeric 20-50 chars)
- [SECURITY] Add rate limiting to all API routes (webhooks, connectivity test, dashboard)

### Improvements

- [IMPROVED] Clean up excessive debug logging in NotificationWebhookController (~25 log calls removed)
- [IMPROVED] Extract shared email-lookup logic into `findNotificationLogByRequestIdOrEmail()` and `extractRecipientEmail()` helpers

## 1.0.0 - 2026-02-11

### Fixes

- [BUG FIX] Fix EMA indicator period parsing in QuerySymbolIndicatorsJob — use last ID segment instead of position 4 to correctly match period parameter from Taapi bulk response

### Improvements

- [IMPROVED] Remove debug logging from BaseQueueableJob handle() method
- [IMPROVED] Remove debug logging from HandlesStepLifecycle completeIfNotHandled()
- [IMPROVED] Remove debug logging and unused Log import from UpsertExchangeSymbolsFromExchangeJob
