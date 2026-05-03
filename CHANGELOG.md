# Changelog

All notable changes to this project will be documented in this file.

## 1.19.0 - 2026-05-03

### Fixes

- [BUG FIX] **WebSocket idle-watchdog now distinguishes data-frames from protocol pings.** `BaseWebsocketClient` adds a `$pingsCountAsAlive` flag (default `true` to preserve the user-data-stream contract — quiet accounts can sit hours without order events but server pings prove TCP is alive). `BinanceApi::markPrices()` opts out (sets `false`) so the strict-data mark-price stream's idle counter only resets on actual data frames. Background: 2026-05-03 incident — mark-price went silent at 21:05, server kept pinging every ~3min, watchdog idle counter cycled 28→88→148s and never reached the 30s threshold consistently because the ping handler bumped `$lastFrameAt`. Force-reconnect didn't fire until a single ping was missed (idle 196s). Stale-price alerts fired during the 3-minute window. Now: silent data with intact TCP-keepalive trips the watchdog within 30-40s.
- [BUG FIX] **Drift-spotter pre-flight syncs orphan candidates from the exchange before alerting.** `CheckDriftsCommand::auditOrphanOrders` now calls `apiSync()` synchronously on every orphan candidate that carries an `exchange_order_id` BEFORE deciding it's an orphan. Any candidate whose post-sync status is terminal (FILLED / CANCELLED / EXPIRED) drops out of the orphan set automatically — no notification fires, no cancel-orphan-orders lifecycle dispatched. Background: order #1219 (ETCUSDT MARKET BUY) was FILLED on Binance but stuck at PARTIALLY_FILLED locally for ~50min after the parent position was force-cancelled via MARKET-CANCEL flatten. The drift watchdog kept firing the alert every 5min even though the exchange said the order was already terminal. Now: first watchdog tick auto-syncs and falls silent.
- [BUG FIX] **Notification body carries exchange + client order IDs.** `position_orphan_orders_detected` email body now includes per-line `exch:<exchange_order_id> client:<client_order_id>` after each Order's local id. Pushover compact body shows the local id + exchange id (single-order case) or comma-separated local ids (multi-order case) so the operator can paste straight into the exchange UI's order search instead of querying the DB to translate.

## 1.18.1 - 2026-05-03

### Fixes

- [BUG FIX] **Orphan-cleanup in-flight guard.** `OrphanReconciler::reconcile` accepts a new `hasInflightPositions` flag. When any local position on the account is in a transitional lifecycle state (`new`, `opening`, `cancelling`, `syncing`), order-orphan classification is skipped for the tick — the limit ladder is mid-placement and local Order rows have not yet been stamped with their `exchange_order_id`s, so the diff would mis-classify legitimate working orders as orphans. Position-orphan classification stays active because position keys (`SYMBOL:DIRECTION`) are stable across the lifecycle. Reproduces the 2026-05-03 false-positive on ETCUSDT/LONG. Wired in `CheckSystemHealthCommand::reconcileAccountOrphans`.

## 1.18.0 - 2026-05-03

### Fixes

- [BUG FIX] **`NotificationService::send` failure containment.** The `$user->notify()` call now runs inside a `try/catch (Throwable)` block. Channel exceptions (Pushover 429, SMTP timeout, expired token, queue connection blip) are logged as `[NotificationService] notify() failed; swallowed to protect the caller` and the method returns `false` instead of propagating up. Notifications are observability — a thrown channel exception used to cascade into "process death → respawn → mark prices stale → watchdog fires more notifications → Pushover 429 again". Confirmed by the 2026-05-03 afternoon incident on the price daemon.

### Improvements

- [IMPROVED] **Dropped the dead `accounts.margin_ratio_threshold_to_notify` column.** The column was scaffolded with the intent of warning the operator when an account's margin ratio approached liquidation territory, but the bot does not own liquidation handling — that is explicitly out of scope (operators handle liquidations directly via the exchange UI). Zero readers in production code, the migration default (1.50) and the `AccountFactory` default (0.80) disagreed on the scale, and the dashboard stub used the unrelated Binance-UI 0–100 percent. Dropped via migration with a corresponding `down()` rollback. Out of product scope, retired rather than wired.

## 1.17.0 - 2026-05-03

### Improvements

- [IMPROVED] **`(float)` → BCMath migration packs 1-12 + nano-pack.** 107 net `(float)` casts removed from `kraitebot/core/src/` (198 → 91 site count). Migrated paths now route precision-sensitive arithmetic through `Kraite\Core\Support\Math::*` instead of IEEE-754 floats. Coverage spans order mappers (Binance / Bitget / Bybit / KuCoin), recovery (open-positions non-zero filter), drift checking (direction inference + relative-tolerance helper), exchange-information mappers (tick / min / max / notional), billing wallet, position accessors (`Position::daily_variation_percentage`), market-regime helpers (`RegimeCalculator::futVolHot`, `MarketShockCircuitBreaker::priorBarPct`), token-scoring proximity (`SupportResistanceProximity::computeMultiplier`), one-way-mode direction filter (`AssignBestTokensToPositionSlotsJob`), and the price-volatility indicator. The remaining 91 casts encode legitimate boundaries (display coercion, log-using statistics, return-type contracts, downstream consumer cascades, final API surfaces) and were classified explicitly as KEEP.

### Fixes

- [BUG FIX] **`indicators_synced_at` skip-stamp.** The `same_indicator_data` skip branch in `ConcludeSymbolDirectionAtTimeframeJob` was the only successful end-to-end path that did not stamp `indicators_synced_at`. Long-timeframe symbols (1d) sat past the 90-minute system-health watchdog threshold for nearly the full day even though the conclude pipeline ran every cron tick and correctly decided "nothing new". The skip branch now stamps the attempt time alongside the existing concluded / exhausted / path-invalid branches. Watchdog reads the column as a liveness signal — every successful end-to-end branch must stamp it.

## 1.16.0 - 2026-05-03

### Features

- [NEW FEATURE] **Per-account orphan-handling flags** (`accounts.allow_other_positions`, `accounts.allow_other_orders`, both nullable booleans default `false`). Toggle whether the operator is allowed to place positions / orders on the exchange account outside Kraite's control. When `false` (Kraite-exclusive), the orphan-cleanup watchdog auto-cancels stray exchange orders and auto-closes stray exchange positions. When `true`, only Kraite-leftover orphans (matched by `client_order_id` against positions Kraite closed within the configurable match window) are cancelled — operator-placed orders / positions are preserved.
- [NEW FEATURE] **`Account::balanceForTrading()` helper.** Returns Binance `available-balance` when at least one `allow_other_*=true` (free margin remaining after locked-in orders + initial margin of all open positions — the conservative figure that keeps us safe when the user is also trading). Returns `total-wallet-balance` when both flags are `false` (Kraite-exclusive — full utilisation). Cold-start fallback to the `accounts.margin` column when no `account-balance` snapshot exists yet.
- [NEW FEATURE] **`Kraite\Core\Support\Health\OrphanReconciler`** — pure-classification helper that compares an exchange-side snapshot (open orders + algo orders + open positions) against Kraite's local DB state and returns an `OrphanReport` of order ids to cancel + position keys to close. Per-account behaviour driven by the `allow_other_*` flag matrix. Pure PHP — no DB queries, no API calls, no Step dispatch. Comprehensive unit-test coverage.
- [NEW FEATURE] **Orphan-cleanup as the eleventh check in `kraite:cron-check-system-health`.** Runs every 5 minutes on Binance + Bitget active accounts, executes inline (cancel + close via per-exchange API client primitives without Step orchestration), emits one Pushover + email per (account, symbol) cleanup cycle via the existing `system_health_alert` canonical. Position-key normalisation handles both hedge and one-way modes (one-way derives LONG/SHORT from `positionAmt` sign so the comparison is consistent with the local DB schema).
- [NEW FEATURE] **`kraite.health_watchdog.orphan_kraite_match_window_minutes` config key** (default `60`, env-overridable). Defines the rolling window during which exchange orders matched to recently-closed Kraite positions are still considered Kraite-leftovers when `allow_other_orders=true`.

### Fixes

- [BUG FIX] **`indicators_synced_at` semantics correction.** Four sites in the conclude pipeline (timeframes-exhausted, path-invalid, missing-history, price-misalignment) now stamp `indicators_synced_at = now()` on no-conclusion outcomes instead of `null`. The column now means "last attempt", not "last successful conclusion" — critical for the system-health watchdog to distinguish "pipeline broken" from "pipeline ran, no direction emerged". Previously every uncon-concludable symbol triggered an `indicator_stale_*` alert every 5 minutes.
- [BUG FIX] **`NotificationMessageBuilder::build()` missing match arm for `system_health_alert`.** The canonical was seeded on 2026-05-02 but the format-time match arm was never added; every `emit()` from the unified watchdog (across all 10 prior checks) silently threw `InvalidArgumentException` at format-time and never delivered Pushover or email. Match arm added — produces title + detail + detected_at + signal name body, with severity passed through. All eleven signals now deliver.

## 1.15.0 - 2026-05-03

### Features

- [NEW FEATURE] **Pure-math token-scoring helpers under `Support/TokenScoring/`.** Three independently-testable multipliers compose the per-candidate selection score: `LogElasticityScorer` (`log(1 + |elasticity|) × |correlation|` so freak high-amplitude tokens stop dwarfing strong-correlation candidates), `CorrelationStabilityWeight` (downscales symbols whose rolling-correlation series is jittery vs ones that hold a steady signal — input is the std-dev of the sliding-window correlation series), `BatchDiversificationPenalty` (downscores a candidate whose 1d BTC correlation profile is too close to any symbol already picked in the same selection batch, forcing structural variety across a 6-LONG / 6-SHORT book). 21 new unit tests covering the helpers in isolation.
- [NEW FEATURE] **`btc_correlation_stability` JSON column on `exchange_symbols`** (additive nullable migration). Per-timeframe std-dev of the rolling-correlation series, captured by `CalculateBtcCorrelationJob` alongside Pearson / Spearman / rolling. Returns null when fewer than two complete sub-windows exist (insufficient kline depth — typically <110 candles given default window 100 / step 10). Read by `CorrelationStabilityWeight` at scoring time; missing data degrades gracefully to multiplier 1.0.
- [NEW FEATURE] **`HasTokenDiscovery` selection scoring rewrite.** Both `selectBestTokenByBtcBias` and `selectBestTokenFallback` now compose `log_elasticity × |correlation| × s_r_proximity × stability_weight × diversification_penalty`. A new `$batchPicks1dCorrelation` array threads through `assignTokensToPositions` capturing each picked symbol's 1d correlation so subsequent slots in the same batch see the diversification penalty. Hard sign filter on the BTC-bias path retained.

### Fixes

- [BUG FIX] **`Position::nextPendingLimitOrderPrice` rung selection.** Previously anchored on `id > last_filled_id` which broke for recovered positions whose Order rows are inserted in non-rung sequence (MARKET row inserted last). Switched to `orderBy(quantity)` so the smallest-qty unfilled rung is always returned, matching the martingale ladder convention (rung 1 = smallest qty, closest to entry).

## 1.14.0 - 2026-05-03

### Features

- [NEW FEATURE] **Test-only `symbol_override` god-mode at priority 0 of token discovery.** New `kraite.position_creation.symbol_override` config consumed by `HasTokenDiscovery::resolveSymbolOverrideForSlot()` ahead of fast-track / BTC-bias / fallback. When `account_id` matches and the configured pair resolves on the account's exchange, the override pins that ExchangeSymbol on the next free slot — bypassing scoring, correlation, elasticity, BTC-bias, S/R proximity, `is_manually_enabled`, and `was_backtesting_approved`. Silent fallback on any miss (account mismatch, unresolvable pair, direction mismatch, already-open). Does NOT raise slot caps. Intended for rehearsing WAP / close / drift flows on a known token.
- [NEW FEATURE] **Manual-close detection branch in `ProcessUserDataEventJob`.** When the user-data daemon receives a reduce-only `FILLED` frame for an order we don't own, against an active local Position on this account+symbol, the worker dispatches `PreparePositionReplacementJob` with `triggerStatus='EXTERNAL_FILL'`. Closes the polling-latency window where Binance's "Close All" placed a reduce-only MARKET we never persisted, leaving DCA legs live. Dedup via pending-Step query so the eventual EXPIRED-driven OrderObserver dispatch collapses to a single in-flight Step.
- [NEW FEATURE] **Per-execution-type dispatch allowlist (`dispatched_executions`).** `kraite.user_data_stream.<exchange>.dispatched_executions` env-driven allowlist. Each entry is the exchange-native execution-type string (Binance: `NEW / TRADE / AMENDMENT / CANCELED / EXPIRED / REJECTED / CALCULATED`, plus synthesized `ALGO_<status>` for ALGO_UPDATE frames that have no `o.x`). Worker calls `Order::updateSaving` only when an inbound event's executionType matches the list — empty list = pure shadow mode (`api_data_stream` audit only). Each execution type is opt-in enabled as its downstream OrderObserver workflow is verified end-to-end against live frames.
- [NEW FEATURE] **`UserDataStreamEvent::reduceOnly` field.** Mapper extracts Binance's `o.R` reduce-only flag so the manual-close detection branch can gate on it without parsing raw payload at the call site.
- [NEW FEATURE] **Synthesized `ALGO_<status>` execution types for `ALGO_UPDATE`.** ALGO_UPDATE frames have no `o.x`; `MapsUserDataStream` synthesizes `ALGO_NEW / ALGO_FILLED / ALGO_CANCELED / ALGO_EXPIRED` from the `o.X` status so the dispatch allowlist can target algo events distinctly from regular ORDER_TRADE_UPDATE events.
- [NEW FEATURE] **`kraite:cron-check-binance-listen-keys-stale` command.** Detects two failure modes the keepalive cron alone cannot surface: (1) an active Binance account with NO listen-key row past the grace window (daemon never initialised it), and (2) a row with `last_keep_alive_at` older than 30 minutes. Per-account dedupe. Threshold well below Binance's 60-min hard expiry so the operator has time to respond before the WS dies.
- [NEW FEATURE] **`kraite:cron-check-system-health` unified watchdog.** Multiple sequential checks across the bot's critical data paths routed through one `system_health_alert` notification with per-signal cache key (5-minute throttle). Replaces the prior narrow `kraite:cron-check-stale-data`. Adding a new health signal is now a single private method, not a notifications-table migration.
- [NEW FEATURE] **`binance_listen_key_stale` and `system_health_alert` notification canonicals seeded.**

### Removals

- [IMPROVED] **`CheckStaleDataCommand` retired.** Functionality folded into `kraite:cron-check-system-health`'s mark-price freshness signal.

## 1.13.0 - 2026-05-02

### Features

- [NEW FEATURE] **`kraite:recover-positions` disaster-recovery command.** Reconstructs the local DB mirror of currently-open positions and their order history by querying the exchange APIs directly (no Steps, no orchestrator — exceptional operator-driven flow). Idempotent: existing rows match by `account_id + exchange_symbol_id + direction + status IN openedStatuses()` for positions and by `exchange_order_id` for orders. Per-position transactions; `StepDispatcher::deactivate()` wraps the whole run; `--dry-run`, `--account_id`, `--token`, `--override` flags. The `--override` path also cancels any in-flight non-terminal step that references about-to-be-wiped positions/orders so the cron path doesn't fire ModelNotFoundException after the rebuild.
- [NEW FEATURE] **Per-exchange recoverers under `Support/Recovery/`** — `Binance`, `Bitget` (live-tested) plus `Kucoin`, `Bybit` scaffolds (untested, no live accounts). Binance recoverer pages `/fapi/v1/userTrades` and `/fapi/v1/allOrders` in 7-day windows (Binance API constraint) up to 35 days back, then walks fills newest-first stopping when accumulated net qty equals `positionAmt` — isolates the slice that built the current open slot from prior closed positions on the same symbol. Bitget recoverer mirrors the same windowing keyed on `tradeSide=open|close`.
- [NEW FEATURE] **Strategy-anchor reconstruction.** Sets `opening_price` from the OLDEST FILLED entry-side order (not current breakeven), back-calculates `profit_percentage` from current TP price vs current weighted-avg breakeven, derives `first_profit_price` from `opening_price ± profit% × opening_price` so the dashboard lifecycle bar renders correctly. Tags `was_waped=true` + `waped_at=last_filled_at` whenever a position has more than one entry-side fill.
- [NEW FEATURE] **Bitget hedge / one-way mode support across all order mappers.** Seven mappers retrofitted with `$account->isHedgeMode()` branching mirroring the Binance pattern: `MapsPlaceOrder` (hedge: `posSide`+`tradeSide`; one-way: `reduceOnly=YES` on close intent), `MapsPlacePlanOrder` (hedge: `tradeSide`; one-way: `reduceOnly=YES`), `MapsPlacePosTpsl` / `MapsPlaceTpslOrder` / `MapsModifyTpsl` / `MapsTokenLeverageRatios` (`holdSide` gated to hedge only — sending it on a one-way account is rejected with `40034 param error`). `MapsPositionsQuery` keyBy now branches on `posMode`: hedge → `symbol:LONG`/`symbol:SHORT`, one-way → `symbol:BOTH` (mirrors Binance one-way semantics). `apiCloseBitget` (caller-side `flashClosePosition`) and `Bitget/PlacePositionTpslJob` position-key lookup also gated.
- [NEW FEATURE] **Bitget code "40774" wired into the position-mode auto-flip path.** `HandlesApiJobExceptions::isPositionModeMismatch` extended to detect Bitget's string-coded mismatch alongside Binance's int family `-4060/-4061/-4062/-4067`. Same atomic flip + reschedule flow on both exchanges. Audit log message is now exchange-agnostic.
- [NEW FEATURE] **Daemon resilience hardening (7 gaps).** GAP 1: `try/catch` around `Step::create` in `onMessage` with JSONL dead-letter file at `storage/logs/user-data-deadletter-YYYY-MM-DD.log` so frames are never lost on DB blips. GAP 2: 30-second listen-key rotation watchdog — daemon detects `binance_listen_keys.listen_key` divergence from its in-memory slot and re-inits the account before the old key expires. GAP 3: per-account `last_frame_at` column on `binance_listen_keys` written by the daemon (throttled to 30s). GAP 4: notification TTLs tuned (account_init_failed bumped 600s → 3600s) + new `websocket_reconnect_storm` and `binance_user_data_memory_restart` canonicals seeded. GAP 6: heartbeat flag file at `storage/app/user-data-daemon.heartbeat` touched every 30s. GAP 7: `BaseWebsocketClient` raises `websocket_reconnect_storm` Pushover after `reconnectAttempt >= 10` without a successful frame. GAP 8: 30-second memory watchdog with `gc_collect_cycles` at 50% threshold and graceful `Loop::stop()` at 512MB → supervisor respawn.
- [NEW FEATURE] **Account `is_active` reaper in the daemon.** Discovery sweep now runs two passes per cycle: reaper closes WS slots for accounts that became ineligible (`is_active=false`, key removed, deleted), spawner opens slots for newly-eligible accounts. Eligibility = `api_system_id=binance AND is_active=true AND binance_api_key IS NOT NULL`. New `binance_user_data_account_reaped` notification canonical fires per reap. Live test verified: deactivate → 60s reap → reactivate → 60s respawn, with the other account uninterrupted throughout.
- [NEW FEATURE] **Public `BaseWebsocketClient::close()`** for callers that need to shut a stream down intentionally. Nulls the connection reference first to avoid the in-flight callback rehydration race.

### Fixes

- [BUG FIX] **Duplicate `binance_listen_keys` create across migrations.** Removed the legacy stub from `2024_11_26_000000_create_martingalian_complete_schema.php` so the canonical `2026_05_01_120100_create_binance_listen_keys_table.php` migration can run cleanly on a fresh DB. Without the fix, every test-suite run with `RefreshDatabase` failed at the second create with `1050 Table already exists`.
- [BUG FIX] **`VerifyPositionExistsOnExchangeJob` priority pass-through.** Children dispatched from this atomic (`ClosePositionJob`, `SmartReplaceOrdersJob`) now inherit `priority` from `$this->step->priority` so a `priority=high` parent's chain stays on the priority lane all the way down.

### Improvements

- [IMPROVED] **`OrderObserver` MARKET-active guard interplay with recovery.** Recovery's historical-fill recreation defaults unknown order types to `LIMIT` rather than `MARKET` so the observer's "one active MARKET per position" guard doesn't reject the second insert when a position has multiple historical entry orders. Bot operates identically on FILLED LIMIT vs FILLED MARKET (both are terminal-state entry records).

## 1.12.0 - 2026-05-01

### Features

- [NEW FEATURE] **Binance user-data-stream daemon (`kraite:stream-binance-user-data`).** Long-running supervised daemon that opens one authenticated WebSocket per Binance account against `wss://fstream.binance.com/private/ws?listenKey=<key>`. Hosts N concurrent connections inside a single ReactPHP event loop with per-account error isolation — a single account's listenKey error or WS hiccup drops only that account's slot, never the whole daemon (architectural improvement over martingalian's `fatalExit` pattern). 60-second discovery sweep auto-initialises new accounts at runtime. `BaseWebsocketClient` extended with a public `handleCallbackAsync` so multiple WS subscriptions register against the singleton loop without each blocking on `Loop::run()`; the daemon then drives the loop once after all accounts are wired.
- [NEW FEATURE] **`api_data_stream` table.** Single generic raw-event sink for every authenticated WebSocket frame across all exchanges. Per-exchange differentiation lives on the `api_system_id` FK, not in separate per-exchange tables. Stores both raw JSON payload and a flat extracted projection (status, normalized_status, price, average_price, original_quantity, filled_quantity, last_filled_price, last_filled_quantity, exchange_order_id, client_order_id, symbol, event_time) so cross-exchange forensic queries stay one-liners. `idempotency_key` UNIQUE catches re-deliveries (worker retries, reconnect-replayed frames, supervisor-restart races) at INSERT.
- [NEW FEATURE] **`binance_listen_keys` table.** Per-account listenKey state — current key (encrypted at rest), last_created_at, last_keep_alive_at, last_keep_alive_status, failure_count for keepalive-failure threshold alerting.
- [NEW FEATURE] **`ProcessUserDataEventJob` step.** Worker that persists every received frame to `api_data_stream`, logs to the `user-data` channel, and (when its execution type is in the per-exchange dispatch allowlist) calls `Order::updateSaving` so the existing `OrderObserver` fires the right downstream Step (`PrepareOrderCorrectionJob` / `ApplyWap` / `ClosePosition` / `PreparePositionReplacementJob`). Runs on the dedicated `user-data-stream` Horizon queue, isolated from `positions` / `default` so cron-driven step bursts never block real-time event reactivity.
- [NEW FEATURE] **`MapsUserDataStream` Binance trait.** Inbound mapper: parses `ORDER_TRADE_UPDATE` / `ACCOUNT_UPDATE` / `MARGIN_CALL` / `listenKeyExpired` / `CONDITIONAL_ORDER_TRADE_UPDATE` envelopes into a normalized `UserDataStreamEvent` DTO. Maps Binance's `o.X` status vocabulary to Kraite's canonical (`CANCELED → CANCELLED` etc.), preserves the native `o.x` execution type so the per-execution-type dispatch allowlist can target it.
- [NEW FEATURE] **`kraite:cron-refresh-binance-listen-keys` cron.** Every-minute keepalive against Binance's `/fapi/v1/listenKey` REST. Persists `last_keep_alive_at` + `last_keep_alive_status`. Three consecutive failures on the same account fire the new `binance_listen_key_keepalive_failed` Pushover canonical.
- [NEW FEATURE] **Per-execution-type dispatch allowlist** under `kraite.user_data_stream.<exchange>.dispatched_executions`. Driven by env (`USER_DATA_STREAM_BINANCE_DISPATCHED_EXECUTIONS`, comma-separated). Each execution type wires through to the OrderObserver path one at a time as its downstream workflow is validated against live events. Empty list = pure shadow recording. v1 ships with `AMENDMENT` enabled — modifications to a tracked Binance LIMIT order on the exchange now trigger `PrepareOrderCorrectionJob` within ~4s end-to-end via the WS path, replacing the previous up-to-60s polling-driven trigger.
- [NEW FEATURE] **Ops alert canonicals.** `binance_user_data_account_connected` (low — per-account connect confirmation, throttled), `binance_user_data_account_init_failed` (high — `initAccount` exception with per-account isolation already triggered), `binance_user_data_listen_key_expired` (medium — daemon auto-recovers), `binance_listen_key_keepalive_failed` (high — third consecutive REST keepalive miss). Migration seeds all four with appropriate cache durations + cache keys.
- [NEW FEATURE] **Configurable `idleTimeoutSeconds` on `BaseWebsocketClient`.** New constructor arg (default behaviour preserved at 30s for the price stream); `BinanceApiClient` exposes a matching `idle_timeout_seconds` config key. The user-data daemon sets 600s — past Binance's 3-minute server-ping interval × multiple cycles — so the watchdog only fires on genuine zombies. Original 30s default would force-reconnect every quiet window because user-data streams are silent until events fire, which would (and did) cascade into a fd-leak supervisor-respawn loop.

### Improvements

- [IMPROVED] **`apiSyncDefault` shadow-validation override.** `Order::updateSaving` from polling sync now writes ONLY `status` — the `price`/`quantity` lines are commented out under a clearly-marked block with restore instructions inline. Reason: drift on price/quantity is the trigger that `OrderObserver::checkForOrderModification` looks for, and we are validating that the user-data WS path is the unambiguous source of `PrepareOrderCorrectionJob` dispatches. Status-driven flows (FILLED → close, CANCELED → replace) keep working through the polling path. Override is temporary — restore once WS-driven correction is end-to-end-verified across more execution types.
- [IMPROVED] `BinanceApiClient` exposes a non-blocking `subscribeToUserStreamAsync` matching the new `BaseWebsocketClient::handleCallbackAsync`. The blocking `subscribeToUserStream` still works unchanged; the price daemon's existing call path is untouched.

## 1.11.0 - 2026-04-30

### Features

- [NEW FEATURE] **Top-up coin curated list (`top_up_coins` table).** Sysadmin-managed list of payment coins users can pick on `/billing`. Seeded with 10 coins (USDT-TRC20/BSC/SOL, USDC-BSC/SOL, BTC, ETH, SOL, LTC, BNB-BSC). Per-coin admin override of the gateway-derived minimum amount (NULL = always fetch live). `Kraite\Core\Models\TopUpCoin::active()` returns the dropdown set.
- [NEW FEATURE] **Engine knob `kraite.top_up_minimum_when_covered_usdt`** (decimal, default 20). The flat USDT floor applied to top-ups when the user wallet already covers the next renewal — discourages micro chip-ins. Bypassed when the user is under-funded.
- [NEW FEATURE] **`Kraite\Core\Support\Billing\NowPaymentsClient::createInvoice` accepts `pay_currency`** to lock the hosted invoice to a specific coin (no picker shown to the user).
- [NEW FEATURE] **Ops alert canonicals.** `websocket_reconnect_triggered` (high — fires inside `BaseWebsocketClient` whenever the idle-timeout watchdog forces a reconnect) and `price_data_stale` (critical — fires from `kraite:cron-check-stale-data` when any non-delisted enabled symbol has `mark_price_synced_at` older than 1 minute). Migration seeds both with appropriate cache durations + cache keys.
- [NEW FEATURE] **`kraite:cron-check-stale-data` command.** External alert for the price-stream's data-side health. Replaces the retired `kraite:watch-price-stream` (which auto-restarted the daemon in a futile loop during the 2026-04-23 silent outage). Pure alert, no auto-restart — the WS client's idle watchdog handles transient stalls; this surfaces unrecoverable cases (URL deprecation, IP ban, gateway outage) to a human within ~1 minute.
- [NEW FEATURE] `Kraite\Core\Models\ExchangeSymbol::isDelisted(): bool` and `delistedAt(): ?Carbon` instance helpers. Combine `is_marked_for_delisting` flag with `delivery_at` (futures delivery contracts that have settled). Single source of truth for delisting checks across the codebase.
- [NEW FEATURE] `scopeNotDelisted` query scope on `ExchangeSymbol` mirroring the `isDelisted` logic. Used by the new stale-data check; available everywhere we filter live symbols.

### Improvements

- [IMPROVED] **Binance USDS-M Futures WebSocket migration (2026-04-23).** `BinanceApiClient::subscribeToStream` now routes to `/market/stream?streams=<name>` (was `/stream?streams=<name>`); `subscribeToUserStream` to `/private/ws?listenKey=<key>` (was `/ws/<listenKey>`). Legacy roots stopped pushing /market and /private streams on the deprecation date — frame-receive count would drop to zero on connect with no error. Comments link to Binance change-notice for future reference.
- [IMPROVED] `BaseWebsocketClient` idle-timeout watchdog now fires `websocket_reconnect_triggered` Pushover alongside the existing log line. Throttled by the canonical's 5-minute cache key on URL.

### Fixes

- [BUG FIX] **`subscribeToStream` migration restores price flow.** Before the fix, the daemon connected, subscribed, and silently received zero frames forever (Binance silently stopped delivering on the legacy URL). Verified post-fix: 1977 / 2254 non-delisted symbols sync within 10 seconds, replication across peer exchanges (Bybit/KuCoin/Bitget) intact via the existing chunked CASE/WHEN UPDATE.

### Schema

- [NEW FEATURE] `2026_04_30_120000_create_top_up_coins_table.php` — id, canonical (unique), display_name, sort_order, is_active, min_amount_override, timestamps. Seeded with 10 coins.
- [NEW FEATURE] `2026_04_30_120100_add_top_up_minimum_to_kraite.php` — adds `kraite.top_up_minimum_when_covered_usdt` (decimal default 20).
- [NEW FEATURE] `2026_04_30_140000_seed_ops_websocket_notifications.php` — seeds `websocket_reconnect_triggered` + `price_data_stale` canonicals.

### Removed

- [BUG FIX] `Kraite\Core\Commands\Cronjobs\WatchPriceStreamCommand` removed. The supervisor-restart loop hid the 2026-04-23 silent outage for 2 days. Replaced by `kraite:cron-check-stale-data` (alert only, no restart). The daemon's internal idle watchdog already handles transient stalls; this command was redundant for healable cases and counter-productive for unrecoverable ones.

## 1.10.0 - 2026-04-29

### Features

- [NEW FEATURE] **Monthly subscription billing — full pivot from daily debit.** `subscription_renews_at` (renewal anchor) is the single source of truth for cycle position. Renewal cron debits one tier rate and pushes the anchor +1 month. No daily debits, no runway-days framing.
- [NEW FEATURE] `Kraite\Core\Commands\Cronjobs\RenewSubscriptionsCommand` (`kraite:cron-renew-subscriptions`) — daily midnight cron with three responsibilities: process due renewals, fire `subscription_low_balance` 7 days before each renewal (only if wallet short), fire `subscription_trial_ending` 2 days before trial expiry (only if wallet short, one-shot lifetime). Replaces the retired `DeductSubscriptionsCommand`.
- [NEW FEATURE] `Kraite\Core\Support\Billing\Wallet::runRenewal(User, ?CarbonInterface)` — atomic monthly renewal. Locks the user row, debits the tier rate, pushes `subscription_renews_at` forward by 1 month (or to an explicit anchor passed by callers like the read-only-unlock path: `now + 1 month - 1 day`).
- [NEW FEATURE] `Kraite\Core\Models\User` pause/resume helpers — `isPaused()`, `pause()` (sets `subscription_paused_at`), `resume()` (clears the timestamp + pushes `subscription_renews_at` forward by the pause duration). Trial keeps wall-clock during pause.
- [NEW FEATURE] `Kraite\Core\Models\User` renewal helpers — `subscriptionCoversNextRenewal(): bool`, `renewalShortfallUsdt(): float`, rewritten `isInClosingMode()` (paused → true; trial active → false; renews_at null or past → true). Drops `walletRunwayDays()`.
- [NEW FEATURE] **NOWPayments integration — first pass.**
  - `Kraite\Core\Models\Payment` — append-only payment-tracking model with status constants (`pending`, `waiting`, `confirming`, `confirmed`, `sending`, `partially_paid`, `finished`, `failed`, `refunded`, `expired`) and `CREDITABLE_STATUSES` (finished, partially_paid).
  - `Kraite\Core\Support\Billing\NowPaymentsClient` — thin Http wrapper for the gateway API. `createInvoice(price_amount, order_id, ipn_callback_url, success_url, cancel_url, order_description, customer_email)` returns hosted invoice URL. `getPayment()` for admin reconcile. Static `fromConfig()` factory pulls credentials from `services.nowpayments`.
- [NEW FEATURE] `WalletTransaction::TYPE_CREDIT_PRORATE_REFUND` — new ledger type for the refund credit written when a user changes plan mid-cycle.

### Improvements

- [IMPROVED] `Kraite\Core\Support\NotificationMessageBuilder` — all 4 billing arms rewritten for renewal-anchored timing. `subscription_low_balance` reads `renews_at` + `monthly_rate_usdt` + `shortfall_usdt`. `subscription_closing_mode` reads `monthly_rate_usdt` + `shortfall_usdt`. `subscription_trial_ending` reads `monthly_rate_usdt` + `shortfall_usdt`. `subscription_topup_confirmed` extended with `renewal_ran` flag — three message branches: renewal applied, balance covers next renewal, still short.
- [IMPROVED] `Kraite\Core\Support\Billing\Wallet::bonusPercentFor()` removed — bonus ladder killed in the monthly model. `WalletTransaction::TYPE_CREDIT_TOPUP_BONUS` constant retained for ledger-history compatibility, but no new rows of that type are written.

### Schema

- [NEW FEATURE] `2026_04_29_120000_switch_subscriptions_to_monthly_rate.php` — renames `subscriptions.daily_rate_usdt` → `monthly_rate_usdt`, multiplies existing values by 30. Reversible.
- [NEW FEATURE] `2026_04_29_120100_add_renewal_columns_to_users.php` — adds `users.subscription_renews_at` + `users.subscription_paused_at` (nullable timestamps).
- [NEW FEATURE] `2026_04_29_130000_create_payments_table.php` — `payments` table tracking each top-up with user_id, nowpayments_payment_id (unique), order_id, pay_currency, pay_amount, price_amount, outcome_amount, outcome_currency, status, invoice_url, credited_at, raw_payload (json).

## 1.9.1 - 2026-04-29

### Fixes

- [BUG FIX] **Bitget WAP no longer fails with `400172 "trigger price cannot be empty"`.** `Bitget\CalculateWapAndModifyProfitOrderJob` previously called the broken `apiModifyTpsl` (Bitget rejects `modify-tpsl-order` for `pos_profit` / `pos_loss` orders across every payload variation). Switched to the `place-pos-tpsl` atomic-pair overwrite — same proven path used by `Bitget\ModifyAlgoOrderJob` for drift-correction. Reads the sibling SL leg's current price and sends both legs in one request so the SL isn't erased. Plan-order IDs are preserved by the endpoint.
- [BUG FIX] Local profit order's `price` and `quantity` now mirrored directly after a successful `place-pos-tpsl` (Bitget contract preserves IDs, no re-query needed). Previously only quantity was mirrored.

### Improvements

- [IMPROVED] New `Bitget\CalculateWapAndModifyProfitOrderJob::findSiblingStopLossOrder()` helper — mirrors the convention from `Bitget\ModifyAlgoOrderJob::findSiblingAlgoOrder()`.
- [IMPROVED] `ExchangeSymbolFactory` default state now sets `was_backtesting_approved=true`. The DB column default is still `false` (added 2026-04-27), so production rows still need explicit operator approval — only the test/factory shorthand assumes a fully-eligible symbol unless a test explicitly overrides to `false`. Unblocks ~30 token-discovery and assignment tests that were silently filtered out by `scopeTradeable()` after the column landed.

## 1.9.0 - 2026-04-29

### Features

- [NEW FEATURE] **Crypto subscription billing — engine** (sans payment-gateway webhook). Per-user wallet, daily-deduct cron, rolling subscription, ledger-backed audit trail. Webhook integration deferred.
- [NEW FEATURE] Schema additions:
  - `subscriptions.daily_rate_usdt` + `subscriptions.trial_days` — tier billing config, live-tunable from the table.
  - `users.wallet_balance_usdt`, `users.trial_started_at`, `users.active_account_id`, `users.trial_days_override` — per-user billing state.
  - `wallet_transactions` table — append-only ledger with type, signed amount, post-write `balance_after` snapshot, description, JSON meta. Customer-dispute resolution reads top-to-bottom.
- [NEW FEATURE] `Kraite\Core\Models\WalletTransaction` — model with `TYPE_*` constants (debit_subscription, credit_topup, credit_topup_bonus, credit_admin, debit_admin) and `isCredit/isDebit` helpers.
- [NEW FEATURE] `Kraite\Core\Support\Billing\Wallet` service — single point of mutation. `credit()` / `debit()` open a transaction, lock the user row (`lockForUpdate`), update the balance and write the ledger row atomically. `bonusPercentFor(amount)` static helper (50→5%, 100→10%, 500→15%). `InsufficientFundsException` on debit underflow.
- [NEW FEATURE] `Kraite\Core\Models\User` billing helpers — `effectiveTrialDays()` (per-user override → tier default), `isTrialActive`, `isTrialExpired`, `walletRunwayDays`, `isInClosingMode`, `subscription` / `activeAccount` / `walletTransactions` relations.
- [NEW FEATURE] `Kraite\Core\Commands\Cronjobs\DeductSubscriptionsCommand` (`kraite:cron-deduct-subscriptions`) — daily cron, deduct-before-usage. Skips trial-active users; on insufficient funds fires `subscription_closing_mode` notification (no row written, balance untouched). On low runway (< 7 days) post-debit fires `subscription_low_balance`. Reads tier rate live every run — Christmas-promo price changes apply immediately. Supports `--dry-run` and `--output`.
- [NEW FEATURE] Trading-guard billing gate — `HasTradingGuards::canOpenNewPositions()` extended to block opens when user is in closing-mode, and (on Starter / 1-account tier) restrict opens to the designated `active_account_id`. Existing positions wind down naturally — only new opens gated.
- [NEW FEATURE] Four billing notification canonicals seeded via migration: `subscription_low_balance`, `subscription_closing_mode`, `subscription_trial_ending`, `subscription_topup_confirmed`. Match arms added to `NotificationMessageBuilder`.

### Improvements

- [IMPROVED] `KraiteSeeder::seedSubscriptions()` no longer references billing columns (`daily_rate_usdt`, `trial_days`) so it remains callable from inside the create-subscriptions migration before those columns exist.

## 1.8.0 - 2026-04-28

### Features

- [NEW FEATURE] **Drift Spotter** — proactive 5-minute audit on top of the every-minute reactive sync. New command `Kraite\Core\Commands\Cronjobs\CheckDriftsCommand` (`kraite:cron-check-drifts`).
  - Scope 1: drifted active positions (10-minute quiet-window filter, dispatches existing `PrepareSyncOrdersJob`).
  - Scope 2: orphan open orders on closed/cancelled/failed parents — ghosts (no `exchange_order_id`) marked `CANCELLED` in DB inline; real orphans dispatch a new lifecycle wrapper.
- [NEW FEATURE] `Kraite\Core\Support\Drift\DriftCheckService` + `DriftChecker` interface + `AccountDriftReport` / `PositionDriftReport` / `OrderDriftReport` value objects. Algorithm ported from `admin.kraite.com`'s drift dashboard. Account-level batched API roundtrip; pure pairing/comparison split out as `analyse()` for testability.
- [NEW FEATURE] `Kraite\Core\Jobs\Lifecycles\Position\PrepareCancelOrphanOrdersJob` — wrapper that hands off to the existing `CancelPositionOpenOrders` lifecycle (used by ClosePositionJob). Reuses the production bulk-cancel + algo-cancel paths end-to-end, including Bitget's per-order fallback.
- [NEW FEATURE] Two new notification canonicals registered in `NotificationMessageBuilder`: `position_drift_detected`, `position_orphan_orders_detected`. Migration `2026_04_28_010000_seed_drift_spotter_notifications.php` seeds them with 60s cache_duration / per-position cache_key (no over-firing within a cycle, fresh fire every 5 minutes per position while drift persists).
- [NEW FEATURE] Backtest sample-size penalty in `Support\Backtest\BacktestSimulator` — overall score now scales linearly down for backtests with under 180 candles. -30 at 0 candles ramping to 0 at 180+. Prevents overstated grades on statistically thin runs (~half a year of 1d data or ~7.5 days of 1h data is the floor).

### Improvements

- [IMPROVED] `CancelSingleAlgoOrderJob`:
  - `startOrFail()` now accepts non-active position statuses (orphan-cleanup) and rejects null `exchange_order_id` (ghost guard) — defense-in-depth against the spotter's own filter.
  - `computeApiable()` catches ignorable apiCancel exceptions (Binance -2011 etc.), marks the order CANCELLED locally, sets a private `idempotentlyResolved` flag.
  - `doubleCheck()` skips apiSync when the idempotent flag is set — apiSync would throw the same not-found error and turn an idempotent success into a verification failure.
- [IMPROVED] `Atomic\Position\CancelAlgoOpenOrdersJob` foreach now wraps each `apiCancel` in try/catch — ignorable exceptions mark CANCELLED locally and continue to the next order, no longer killing the rest of the batch on a single stale row.
- [IMPROVED] `BitgetExceptionHandler::ignorableHttpCodes` populated with `200/"22001"` ("no order to cancel") and `200/"43001"` ("Order does not exist"). Cancel-of-missing-order now flows through the idempotent path on Bitget. String form because Bitget body codes are string-typed (unlike Binance/Bybit ints).
- [IMPROVED] `BybitExceptionHandler::ignorableHttpCodes` adds `200/110001` (futures cancel of missing order). `170213` intentionally kept retryable-only — documented in code (eventual-consistency lag on spot place is more common than true missing order; promoting to ignorable would silently swallow legitimate retry-worthy cases).
- [IMPROVED] `BybitApiClient` no longer ships `X-BAPI-API-KEY` on public requests. The header now lives only on signed requests (alongside `X-BAPI-TIMESTAMP` / `X-BAPI-SIGN` / `X-BAPI-RECV-WINDOW`). Public `/v5/market/*` calls now use Bybit's IP rate-limit bucket instead of the much smaller per-key bucket — fixes the 02:05 retCode 10006 originally mislabelled as a Binance issue.
- [IMPROVED] `NotificationMessageBuilder` registers the two new canonicals with action-breakdown email body (per-order list with `[ghost]` tag) and pushover summary listing both inline DB cancellations and lifecycle dispatches.
- [IMPROVED] `CoreServiceProvider`:
  - Registers `CheckDriftsCommand` in the boot commands array.
  - Binds `DriftChecker` interface → `DriftCheckService` implementation.

## 1.7.11 - 2026-04-27

### Fixes

- [BUG FIX] **WAP silent failure on one-way mode accounts** — `CalculateWapAndModifyProfitOrderJob::buildPositionKey()` now honours `account.on_hedge_mode`. Hedge mode keys remain `<symbol>:LONG`/`<symbol>:SHORT`; one-way mode keys become `<symbol>:BOTH` to match `MapsPositionsQuery` snapshot keys. Pre-fix, every WAP attempt on a one-way account threw `NonNotifiableException` and silently aborted, leaving stale TP at the original opening price after limit fills. Affected Binance Only Account (id 5) on 2026-04-27 (JTO/USDT pos 289 — repaired manually).
- [BUG FIX] Bitget shares the same fix via inheritance — one-way Bitget accounts would have been affected identically had any been live.

## 1.7.10 - 2026-04-27

### Features

- [NEW FEATURE] **BSCS Phase 2.1C — Size adaptation** — multiplicative margin scaling on new opens during fragile regimes and crowded books.
- [NEW FEATURE] `Support\MarketRegime\FragileMarginMultiplier` — linear ramp 1.0→0.5 across BSCS scores 60-79 (Fragile band). Returns 1.0× outside the band. Operator-tunable via `kraite.market_regime.fragile.{lower_bound,upper_bound,max_reduction_pct}`. 7 unit tests.
- [NEW FEATURE] `Support\MarketRegime\CrowdingMultiplier` — reads `DirectionalBookRisk` + direction, downscales 1.0→0.5 across `largest_side_ratio` 0.7→1.0 ONLY when direction matches the dominant side. Empty side stays at 1.0× (locked policy: no upscale without backtest evidence). 8 unit tests.
- [NEW FEATURE] `PreparePositionDataJob::compute()` now applies `final_margin = base_margin × fragileMultiplier × crowdingMultiplier` between the base-margin computation and the validity check. Step response includes `base_margin`, `fragile_multiplier`, `crowding_multiplier` for forensic visibility on every prepare step.
- [NEW FEATURE] Config block `kraite.market_regime.crowding` with `threshold` (default 0.7) + `max_reduction_pct` (default 50).

### Deferred (Phase 2.2 vs original 2.1C scope)

- Blocked-open simulation log (`blocked_open_simulations` table + `LogBlockedOpenJob` + 24h/72h outcome evaluator) deferred. Non-trivial outcome-replay logic; not gating-critical. Will design with real cooldown-event data in Phase 2.2.

## 1.7.9 - 2026-04-27

### Features

- [NEW FEATURE] **BSCS Phase 2.1B — DirectionalBookRisk + 3-tier staleness.**
- [NEW FEATURE] `Support\MarketRegime\DirectionalBookRisk` — DTO computing portfolio shape from open positions (long/short counts, margin-at-risk per side measured by margin × leverage notional, largestSide, largestSideRatio, netDirectionalBias). Single-query factory `current()`. 9 unit tests pinning every accessor + the BALANCED-on-equal edge case + closed-position exclusion.
- [NEW FEATURE] `BlackSwanIndex::portfolioRisk()` exposes the DTO; `toArray()` now includes a `portfolio_risk` block ready for the admin dashboard's "Book risk: LONG crowded — 8/10 positions, 73% of margin" line.
- [NEW FEATURE] `Enums\BscsStaleness` — 3-tier model `Fresh` / `StaleSoft` / `StaleHard` replacing the binary fail-open. `BlackSwanIndex::staleness()` returns the verdict; `shouldBlockOpens()` now FAILS OPEN under `StaleHard` (score age > 6h or never computed) regardless of cooldown state — "missed pause" is preferable to "stuck halt" on a broken compute pipeline. `StaleSoft` (115min < age ≤ 6h) preserves the existing cooldown so a single missed cron doesn't whipsaw the gate.
- [NEW FEATURE] `AnalyseBscsJob` now fires the `market_regime_compute_stale` notification when `staleness() === StaleHard`. Activated the canonical (was inert from Phase 1). Cache duration 3600s throttles repeat firings during a multi-hour outage.
- [NEW FEATURE] Config `kraite.market_regime.freshness.stale_hard_seconds` default 21600 (6h, env: `MARKET_REGIME_STALE_HARD_SECONDS`).

## 1.7.8 - 2026-04-27

### Features

- [NEW FEATURE] **BSCS Phase 2.1A — MarketShockCircuitBreaker (cascade-in-progress detector)**. New 1-minute cron `kraite:cron-detect-market-shock` reads recent 15m klines for BTC + 4 reference alts and arms the SHARED `bscs_cooldown_until` column when any of four trigger rules fires: BTC ≤ -3%/15m, BTC ≤ -5%/1h, alt-basket ≤ -7%/1h, or correlation ≥ 0.85 + |BTC 1h| ≥ 3%. Closes the hourly BSCS compute blind spot — worst-case latency from cliff-start to gate-arm drops from ~50 min to ~1-15 min.
- [NEW FEATURE] `Support\MarketRegime\MarketShockCircuitBreaker` — pure-PHP value object computing the 4 trigger rules. 9 unit tests pinning each rule independently + the calm baseline + multi-rule fan-out + insufficient-data graceful no-fire.
- [NEW FEATURE] `Jobs\Models\MarketRegime\DetectMarketShockJob` — loads klines from `candles` table, runs the breaker, arms the cooldown silently if one is already active (prevents Pushover spam during a cascade that keeps firing minute after minute), respects operator override, fires `market_shock_circuit_breaker` notification on fresh arming. 6 feature tests pin the state machine.
- [NEW FEATURE] `Commands\Cronjobs\DetectMarketShockCommand` — thin step-dispatcher trigger.
- [NEW FEATURE] New notification arm `market_shock_circuit_breaker` (active by default) — text describes which rule(s) fired + raw move percentages.
- [NEW FEATURE] Config block `kraite.market_regime.shock` with 4 thresholds + `cooldown_hours` (default 24, same as BSCS).

## 1.7.7 - 2026-04-27

### Features

- [NEW FEATURE] `kraite:cron-fetch-klines --reference-set --canonical=<X>` mode. Fetches klines only for the BSCS reference basket (`config('kraite.market_regime.symbols')`, default BTC + ETH/SOL/BNB/XRP) on the chosen exchange, at the requested timeframe. Foundation for the upcoming 15-minute kline coverage that the cascade-detection cron (Phase 2.1A) will read. Missing tokens skipped silently; missing canonical fails loudly with --canonical required. No correlation/elasticity steps (reference set drives BSCS / cascade only — no per-pair correlation needed). 6 tests cover the full flag matrix.

## 1.7.6 - 2026-04-27

### Fixes

- [BUG FIX] `BlackSwanIndex::ageSeconds()` now returns the absolute diff. Carbon's `diffInSeconds()` is signed by direction; the call was returning negative values when `synced_at` was in the past (which is always the steady-state case). Caller-facing: dashboards / freshness logic that consumed the raw value would compare a negative number against a positive `freshness_max_seconds` and silently mis-classify staleness.

## 1.7.5 - 2026-04-27

### Features

- [NEW FEATURE] **`Kraite\Core\Support\MarketRegime\BlackSwanIndex`** — read-side façade exposing the BSCS state through one ergonomic API: `score()`, `band()`, `shouldBlockOpens()`, `isCooldownActive()`, `isOverrideActive()`, `isStale()`, `ageSeconds()`, `toArray()` (full dashboard payload including the latest snapshot's sub-signal grid). Single static `current()` factory loads kraite singleton + latest snapshot in one place.
- [NEW FEATURE] **System cooldown gate (Phase 2)** wired through `HasTradingGuards::canOpenPositions()`. When the BSCS score reaches the configured threshold (default 80), the new `kraite:cron-analyse-bscs` cron arms a 24h cooldown that pauses new opens at the global guard. On natural expiry, score is re-evaluated: still high → re-arm another window, recovered → release. Operator override (`bscs_override_until` in the future) bypasses the cooldown.
- [NEW FEATURE] **`AnalyseBscsJob` + `kraite:cron-analyse-bscs`** at `:55` past the hour (5 min after the BSCS compute cron). State machine: cooldown_armed / cooldown_already_active / cooldown_rearmed / cooldown_released / noop_below_threshold / noop_override_active.
- [NEW FEATURE] Activated `market_regime_critical` and `market_regime_recovered` notifications (was inert in Phase 1). Critical fires once per cooldown arming, recovered fires when the system releases. Notification text rewritten to describe the actual cooldown semantics instead of the old Phase 1 "telemetry only" wording.
- [NEW FEATURE] Migration adds `kraite.bscs_cooldown_until` (datetime, nullable). Distinct from `bscs_override_until` — system-driven (lower opens) vs operator-driven (raise opens).

### Configuration

- [NEW FEATURE] `config/kraite.php` `market_regime.cooldown` block with `threshold` (default 80, env: `MARKET_REGIME_COOLDOWN_THRESHOLD`) and `hours` (default 24, env: `MARKET_REGIME_COOLDOWN_HOURS`).

### Tests

- [NEW FEATURE] `tests/Unit/Support/MarketRegime/BlackSwanIndexTest` — 10 cases pinning the read-side façade contract (score / band / cooldown / override / stale / payload, plus the override-beats-cooldown precedence rule).
- [NEW FEATURE] `tests/Feature/MarketRegime/AnalyseBscsJobTest` — 7 cases pinning the cooldown state machine (arm, no-op while active, re-arm on expiry with high score, release on expiry with recovered score, no-op below threshold, no-op while override active, configured cooldown_hours window).
- [NEW FEATURE] `tests/Feature/HasTradingGuardsBscsGateTest` — 5 cases pinning the gate wiring (allow when no cooldown, block when cooldown future, override beats cooldown, allow_opening_positions=false still wins, allow when cooldown expired).

## 1.7.4 - 2026-04-27

### Fixes

- [BUG FIX] **`range_blowout` formula corrected** to per-day comparison per spec intent. Was implemented as `max of 24 individual hourly ranges ÷ mean of 14d × 24 = 336 hourly ranges` which over-fired because almost any day has at least one hourly bar beating the global mean (signal stuck at ~3× threshold in production, score pinned at 40/elevated all day). Now reads as `today's full 24h range ÷ mean of past 14 daily 24h ranges` — matches the spec's "intraday range exploding — cascade signature" description. First post-fix snapshot dropped from chronic 40 to 20 (band: calm).
- [BUG FIX] **Phase 1 invariant: `bscs_block_active` always written FALSE.** Previously the compute job set the flag based on `score >= threshold` even during the observation window, which would have flipped the (currently-disconnected) gate the moment any score crossed 80. Pinned by feature test. Phase 2 will reintroduce the threshold logic as part of wiring `HasTradingGuards`.
- [BUG FIX] **`bscs_freshness_max_seconds` default bumped 5400 → 6900** (90 → 115 min). Cron stamps `synced_at` at `xx:00` but runs at `xx:50` of next hour; natural age was 60–110 min between successful runs, leaving only 30 min margin under the old default. New ceiling tolerates one missed tick. Includes a migration that bumps existing rows still on the old default while leaving operator-tuned values alone.

### Improvements

- [IMPROVED] Spec doc `~/docs/kraite/black-swan-logic.md` updated with Phase 1 implementation notes — sub-signal formulas now state the implementation behaviour explicitly (per-day for `range_blowout`, daily-average denominator for `fut_vol_hot`), Phase 1 observed deviations from original spec text are documented with rationale, and Phase 2 / dashboard / override-UI work is enumerated as still-deferred.

### Tests

- [NEW FEATURE] `tests/Unit/Support/MarketRegime/RegimeCalculatorTest` — 2 new regression guards on `range_blowout`: (a) fires when today's daily range balloons even with all hourly bars staying narrow individually, (b) stays under threshold when day-by-day ranges are uniform (no spurious fire from per-hour granularity).
- [NEW FEATURE] `tests/Feature/MarketRegime/ComputeMarketRegimeJobTest` — 5 cases pinning the Phase 1 contract: snapshot row written with all 5 sub-signals, kraite singleton denormalised, **Phase 1 invariant `bscs_block_active=false` regardless of score or pre-state**, insufficient-history graceful skip, modelLog audit trail.

## 1.7.3 - 2026-04-27

### Fixes

- [BUG FIX] `ExchangeSymbolObserver::decimalsEqual()` now accepts `string|int|float|null` instead of strict `?string`. Closes a TypeError that surfaced from the admin BacktrackingController where `(float)` casting on the gap input poisoned the observer mid-fan-out. Symptom: every admin save that changed a gap value silently logged an exception and aborted propagation, leaving sibling rows un-synced. Math::equal already accepts the broader spectrum — only the wrapper signature was too tight.

## 1.7.2 - 2026-04-27

### Features

- [NEW FEATURE] `ExchangeSymbolObserver::propagateGapsToSiblings()` — asymmetric Binance→siblings fan-out for `percentage_gap_long` and `percentage_gap_short`. Same shape as the per-symbol TP/SL observer (saveQuietly recursion guard + precision-safe Math::equal idempotency check). Closes the gap-asymmetry bug observed on AGLD / HYPE / JUP where a Binance gap edit was not reflected on Bitget / Bybit / KuCoin sibling rows.
- [NEW FEATURE] `propagateBacktestingReviewToSiblings()` (renamed from `propagateBacktestingApprovalToSiblings`) now propagates BOTH `was_backtesting_approved` AND the new `backtesting_review_status` admin-side review state. Symmetric (any source row), saveQuietly on siblings.

### Fixes

- [BUG FIX] `Jobs\Atomic\Order\PlaceStopLossOrderJob` and `Jobs\Atomic\Order\Bitget\PlacePositionTpslJob` now resolve SL through `resolveStopLossPercentage()` — snapshot first, with a TpSlResolver fallback to the account default when the snapshot is null. Closes the half-baked-deploy hazard where a worker running the new placer against a position prepared by an old `PreparePositionDataJob` (no snapshot) would silently skip SL placement via `startOrFail() === false`. Pre-migration positions also benefit — no backfill ever needed again.

## 1.7.1 - 2026-04-27

### Features

- [NEW FEATURE] **Per-symbol TP/SL overrides with account fallback.** `exchange_symbols.profit_percentage` (decimal(6,3) null) + `exchange_symbols.stop_market_percentage` (decimal(5,2) null) hold backtest-validated overrides. `accounts.override_tp` + `accounts.override_sl` (boolean, default false) act as kill-switches. Resolution rule (independent for TP and SL): symbol-NULL → use account; account override true → use account; otherwise use symbol value. Snapshotted onto `positions.profit_percentage` + new `positions.stop_market_percentage` at `PreparePositionDataJob` so mid-life symbol/account edits never retroactively rewrite an open position's exit policy.
- [NEW FEATURE] `Support\TpSlResolver::resolve()` — pure-string helper that encodes the resolution rule. String-in / string-out (no float casts — preserves decimal precision for downstream Math comparators). 8 unit tests covering the full matrix (null/empty/value × override true/false).
- [NEW FEATURE] `ExchangeSymbolObserver::propagateTpSlOverridesToSiblings()` — asymmetric Binance→siblings fan-out for `profit_percentage` + `stop_market_percentage`. Only Binance edits propagate (prevents Bitget→Binance→others deadlock that a symmetric observer would create). Precision-safe `Math::equal` comparison skips idempotent re-saves.
- [NEW FEATURE] `kraite:cron-purge-failed-backtested-klines` hourly command (`Commands\Cronjobs\PurgeFailedBacktestedKlinesCommand`). Purges candles for ExchangeSymbols whose backtest review was rejected.

### Improvements

- [IMPROVED] `Support\Backtest\BacktestSimulator` overall-score weights recalibrated. Stops are the dominant signal now: `stops × 5` (was 15), `rung_depth × 0.05` (was 0.20), `MAE_max × 0.10` (was 0.15), `worst-bucket × 0.3` (was 0.5). Calibration target: ~10% stops → F (≤30), ~5% → D (~60), ~2% → B (~80), ~0.5% → A (~90+). Old weights let 12% stops still grade B because depth/MAE/worst-bucket chipped tiny deductions and stops alone weren't decisive.
- [IMPROVED] `Jobs\Atomic\Order\PlaceStopLossOrderJob` + `Jobs\Atomic\Order\Bitget\PlacePositionTpslJob` now read `position->stop_market_percentage` (snapshot) instead of `account->stop_market_initial_percentage`. Doc updated.

## 1.7.0 - 2026-04-27

### Features

- [NEW FEATURE] **Black Swan Composite Score (BSCS) — Phase 1 telemetry.** Hourly job (`kraite:cron-compute-market-regime`, `:50`) computes a 0-100 portfolio-fragility score from BTC + 4 reference alts (ETH/SOL/BNB/XRP) on 1h klines. Five binary sub-signals × 20 = score, mapped into bands (calm/elevated/fragile/critical). Phase 1 persists snapshots and denormalises onto the `kraite` singleton — does NOT touch trading flow. New: `Enums\RegimeBand`, `Support\MarketRegime\RegimeCalculator`, `Models\MarketRegimeSnapshot`, `Jobs\Models\MarketRegime\ComputeMarketRegimeJob`, `Commands\Cronjobs\ComputeMarketRegimeCommand`, table `market_regime_snapshots`, 8 cols on `kraite`. 11 unit tests covering each sub-signal + threshold logic + band mapping. First production snapshot landed at 00:00 UTC: score=20, band=calm. Spec: `~/docs/kraite/black-swan-logic.md`. Phase 2 will wire the score into `HasTradingGuards` + Fragile-band margin scaler.
- [NEW FEATURE] `exchange_symbols.was_backtesting_approved` boolean column (default false). Required-true gate in `isTradeable()` + the `Tradeable` Eloquent scope (both main + Binance-counterpart subquery sites). Trading is blocked until an operator flips this flag per token. **Existing positions stay open; only new opens are blocked.** Companion accessor `ExchangeSymbol::getOthersFromExchanges()` returns sibling rows on other exchanges via `symbol_id` FK (the canonical post-`base_asset_mappers`-drop linkage). The `ExchangeSymbolObserver::saved()` hook detects `wasChanged('was_backtesting_approved')` and propagates the new value to all siblings via `saveQuietly()` — symmetric (any exchange as source), no recursion fan-out, idempotent (skip if already match).
- [NEW FEATURE] Three notification canonicals seeded (inert in Phase 1, `is_active=false`): `market_regime_critical`, `market_regime_recovered`, `market_regime_compute_stale`. Phase 2 will activate.

### Fixes

- [BUG FIX] `Bitget\MapsAccountQueryTrades::resolveQueryTradeResponse` now normalises `side` on `tradeSide=close` fills (Bitget hedge mode reports the original opening side on every fill — the open/close discriminator is `tradeSide`). Also reverses the fill list to oldest-first to match Binance's response convention. Without these two normalisations the cross-exchange `extractClosingPriceFromTrades` extractor never matched a close fill on Bitget and fell through to its `end($trades)` fallback — silently persisting an unrelated old-fill price as `closing_price` on every Bitget close (verified live against the SFPUSDT 2026-04-26 closes which all wrongly recorded $0.3435). Hedge-mode-only assumption documented inline.

### Improvements

- [IMPROVED] `DisableVolatileTokensCommand::ALLOWED_TOKENS` — `M` removed (operator decision).
- [IMPROVED] `Support\Backtest\BacktestSimulator` — refinements to walk-forward simulator (~50 lines net change).
- [IMPROVED] `Commands\Backtest\BacktestTokenCommand` — minor option tweaks.

## 1.6.2 - 2026-04-26

### Security

- [SECURITY] `Account` and `Kraite` models now declare `$hidden` for every credential column (binance/bybit/kraken/kucoin/bitget keys, secrets, passphrases, plus admin-only coinmarketcap/taapi/pushover keys). Eloquent serialization (`toArray()`, `toJson()`, `jsonSerialize()`) was the leak path for `api_request_logs.payload` — every Bitget request was persisting plaintext API key / secret / passphrase via `ApiProperties::set('account', $account)` → JSON encode. Direct attribute access and the `all_credentials` accessor still work (functional access preserved); only serialization is filtered.
- [SECURITY] New `Support\HeaderSanitizer` redacts auth headers in `BaseApiClient::prepareLogData()` before persistence to `api_request_logs.http_headers_sent`. Covers Bitget (`ACCESS-KEY`, `ACCESS-SIGN`, `ACCESS-PASSPHRASE`), Binance (`X-MBX-APIKEY`), KuCoin (`KC-API-*`), Bybit (`X-BAPI-*`), Kraken (`API-KEY`, `API-SIGN`), generic (`Authorization`, `X-API-KEY`). Case-insensitive matching; timestamp headers (`*-TIMESTAMP`) and non-auth headers pass through unchanged so log rows stay useful for debugging.

### Fixes

- [BUG FIX] `Bitget\ModifyAlgoOrderJob` (new, atomic) restores drifted Bitget position-level TP/SL orders back to their `reference_price` by re-calling `place-pos-tpsl` (the only endpoint that works for `pos_profit` / `pos_loss` orders — `cancel-plan-order` returns silent no-op and `modify-tpsl-order` rejects with HTTP 400 on every field combination). `place-pos-tpsl` overwrites atomically AND preserves the existing plan-order IDs (verified live against FETUSDT pos 421, 2026-04-26). Pairs in the sibling leg's unchanged price so neither side is accidentally cleared.
- [BUG FIX] `Bitget\PrepareOrderCorrectionJob` (new, lifecycle override) replaces the base cancel+recreate algo workflow with `ModifyAlgoOrderJob` for Bitget accounts. LIMIT-order branch identical to base (`apiModify` works on Bitget regular orders the same way it does on Binance).
- [BUG FIX] `OrderObserver` now resolves `PrepareOrderCorrectionJob` via `JobProxy::with($position->account)->resolve(...)` so Bitget accounts get the override automatically. Previously hardcoded the base class, bypassing the resolution chain entirely. Binance behavior preserved exactly — JobProxy falls back to base when no override exists.
- [BUG FIX] `ForbiddenHostnameObserver::created()` now logs notification dispatch failures via `Log::error` instead of swallowing them silently. Hid a Redis outage during a real ban event.
- [BUG FIX] Slow-query `DB::listen` closure now has a re-entry guard. The closure body itself executes queries (SlowQuery insert + Notification reads + NotificationLog write); each fired `DB::listen` again, and a slow notification-related query could trigger a second `slow_query_detected` notification, looping until the cache throttle caught up.
- [BUG FIX] `NotificationMessageBuilder::build()` now throws `InvalidArgumentException` on unknown canonicals instead of silently returning a placeholder notification. Typo at a call site previously shipped an incoherent live notification with no runtime error. `NotificationService::send()` catches the throw, logs via `Log::error`, and returns false — caller never crashes.

### Improvements

- [IMPROVED] New `Enums\NotificationLogStatus` enum centralises status string values previously scattered as bare literals across `NotificationLogListener`, `NotificationLogObserver`, `NotificationWebhookController`, and `NotificationLog` model scopes. Casing typo / drift risk eliminated.
- [IMPROVED] `NotificationService` now caches the `Notification::where('canonical')->first()` lookup in-process. Previously a per-call DB read on hot paths (`ApiRequestLog::saved`, every position-lifecycle step). `flushNotificationCache()` exposed for test isolation.
- [IMPROVED] `MapsModifyTpsl` mapper marked with explanatory comment that it currently produces requests Bitget rejects (HTTP 400 on every field combination tried) and is no longer used by drift correction (which now uses `place-pos-tpsl`). Left in place because `Order::apiModifyTpsl()` is still wired into the WAP recalc path — fixing that is a separate concern.
- [IMPROVED] Email notification template (`emails/notification.blade.php`) header — `MARTINGALIAN` → `KRAITE`.

## 1.6.1 - 2026-04-26

### Fixes

- [BUG FIX] `Bitget\PlacePositionTpslJob::doubleCheck()` killed already-placed positions on transient HTTP 429 from `getPositions`. Root cause: `doubleCheck()` runs OUTSIDE the `compute()` try/catch that wires `handleApiException`, so a rate-limit during the verification re-query bypassed the retry path and went straight to `Failed` → `CancelPositionJob` cancelled the TP/SL we'd just placed (THETAUSDT, position 423, 2026-04-26). Two-part fix: fast-path returns `true` when both Order rows already carry an `exchange_order_id` from `computeApiable()` (the 99% case — Bitget specificity vs Binance which returns IDs in the place response and never needs the re-query); slow-path wraps the re-query in `try/catch` so transient failures return `false` (framework retries `doubleCheck`) instead of escaping as a step crash.
- [BUG FIX] `Bitget\MapsAccountQueryTrades` trait + `BitgetApi` were silently breaking `closing_price` population on every Bitget close. The mapper exposed `prepareAccountQueryTradesProperties` / `resolveAccountQueryTradesResponse` while the cross-exchange interface (used by `Position::apiQueryTokenTrades()` → `UpdateRemainingClosingDataJob`) calls `prepareQueryTokenTradesProperties` / `resolveQueryTradeResponse`. `BitgetApi` exposed `getOrderFills` while the same chain calls `accountTrades` by name. Result: every Bitget close logged "Method prepareQueryTokenTradesProperties does not exist for this API" and `positions.closing_price` stayed null. Renamed all three methods to match the cross-exchange interface used by Binance/Bybit/KuCoin.

### Improvements

- [IMPROVED] `Bitget\PlacePositionTpslJob::complete()` now fires `profit_order_placed` + `stop_loss_placed` `appLog` events — symmetric audit trail with Binance's separate `PlaceProfitOrderJob` / `PlaceStopLossOrderJob` (both already fire the same event names). Previously Bitget closes left no `app_logs` breadcrumb for TP/SL placement.
- [IMPROVED] `AccountFactory` — removed stale `stop_market_wait_minutes` default. Companion to the in-flight `2026_04_26_150000_drop_stop_market_wait_minutes_from_accounts` migration in `kraitebot/ingestion`; without this every test using `Account::factory()->create()` was failing with "Unknown column 'stop_market_wait_minutes'".

## 1.6.0 - 2026-04-26

### Features

- [NEW FEATURE] **Dual position-mode support** for Binance Futures (Hedge AND One-Way). Triggered by Karine Esnault account (#5) — her Binance is in One-Way mode while Kraite previously assumed Hedge for every account, causing every order placement to fail with -4061 "POSITION_SIDE_NOT_MATCH". The feature is reactive (no proactive polling, no mutation of user's Binance settings on their behalf) and self-healing on mode drift. Full design in `docs/02-features/dual-position-mode.md`.
- [NEW FEATURE] `accounts.on_hedge_mode` boolean column (migration `2026_04_26_110850_add_on_hedge_mode_to_accounts`). DB default `true` preserves existing live accounts (Bruno's hedge-mode book stays untouched). Application-layer default `false` via `AccountFactory` so new traders default to one-way (Bruno's stated preference: "one-way is most popular").
- [NEW FEATURE] `Account::isHedgeMode()` / `Account::isOneWayMode()` accessors on `HasGetters` trait. Mapper layer reads these to decide payload shape.
- [NEW FEATURE] `AccountFactory::hedgeMode()` / `oneWayMode()` factory states for tests that need either shape.
- [NEW FEATURE] `HandlesApiJobExceptions::isPositionModeMismatch()` + `autoFlipPositionMode()` — auto-flip catch in the central exception handler. Listens for the documented Binance position-mode error family `(-4060, -4061, -4062, -4067)` so a Binance-side rename or asymmetric variant doesn't silently break the auto-flip. Atomic flip via `lockForUpdate` so concurrent failures from sibling atomic jobs serialise on the row lock. Reschedules the failing step with `rescheduleWithoutRetry` (2s `dispatch_after`) — no retry-counter burn, no `Position::updateToFailed` cascade, no symbol auto-block. Audit goes to BOTH `Log::warning('position_mode_auto_flip', ...)` for ops grep AND `Account::modelLog('position_mode_auto_flip', ...)` for the per-account forensic timeline.

### Improvements

- [IMPROVED] `BinanceApiDataMapper::preparePlaceOrderProperties()` (via `MapsPlaceOrder`) — branches on `account->isHedgeMode()`. Hedge: sets `positionSide=LONG/SHORT` as before. One-way: omits `positionSide` (Binance rejects with -4061 if sent), sets `reduceOnly=true` on closing-intent orders (`PROFIT-LIMIT`, `PROFIT-MARKET`, `STOP-MARKET`, `MARKET-CANCEL`). Without `reduceOnly` in one-way mode a SELL on a LONG position would OPEN a SHORT instead of closing the LONG.
- [IMPROVED] `BinanceApiDataMapper::preparePlaceAlgoOrderProperties()` (via `MapsPlaceAlgoOrder`) — same hedge/one-way branch on `positionSide`. `closePosition=true` stays in BOTH modes (works universally for closing whatever direction is open at trigger time). Never sets `reduceOnly` regardless of mode — `closePosition` and `reduceOnly` are mutually exclusive per Binance docs (-4062 if both set).
- [IMPROVED] `AssignBestTokensToPositionSlotsJob::countPositionsByDirection()` — added a one-way branch. Hedge response: one row per `(symbol, positionSide=LONG|SHORT)` with positive `positionAmt`. One-way response: one row per symbol with `positionSide=BOTH` and SIGNED `positionAmt` (positive=LONG, negative=SHORT, zero=empty). Without this branch the slot counter would falsely return zero for one-way accounts that have open positions, and slot calc would think the account is empty.

## 1.5.10 - 2026-04-26

### Features

- [NEW FEATURE] `Support/Backtest/BinanceRestCandleFetcher` — fills the recency gap left by `BinanceVisionCandleFetcher` (Vision only serves completed months; this fetcher pulls live REST candles for the current month so backtest windows stay continuous through "today").

### Improvements

- [IMPROVED] `Support/Backtest/BacktestSimulator` — significant evolution of the walk-forward simulator (~+270 lines): expanded outcome classification, refined ladder progression, and additional helpers driving the admin UI's backtest browser.
- [IMPROVED] `Support/Backtest/TaapiCandlesFetcher` — incremental refinement of the recency top-up path between Vision's last complete month and live.
- [IMPROVED] `Commands/Backtest/BacktestTokenCommand` — dropped the `--non_reboundable_only` filter (subsumed by the simulator's richer outcome classification); usage docstring updated to surface `--skip_stop_loss` instead.

## 1.5.9 - 2026-04-25

### Fixes

- [BUG FIX] `AssignBestTokensToPositionSlotsJob::createPositionSlots()` — slot calculation + position INSERTs now run inside a single `DB::transaction` with a row-level `lockForUpdate` on the `accounts` row. The 2026-04-25 17:33 incident created 2 SHORT positions (#241, #242) when only 1 SHORT slot was free because two concurrent `AssignBest` runs both read the same `dbShorts=5` pre-state and both inserted before either committed — textbook check-then-act race. Concurrent runs now serialise on the lock; the second one reads the post-commit count and finds zero available slots. Pinned by `tests/Feature/Jobs/AssignBestTokensAtomicSlotReservationTest`.

### Improvements

- [IMPROVED] `CreatePositionsCommand::attemptOpeningPositionsForAccount()` — backed out the v1.5.7 command-entry idempotency guard. With the AssignBest atomic slot reservation in place that guard is redundant: the slot cap is now an enforced invariant rather than a check the command had to second-guess. Removing redundant defence-in-depth keeps the codebase readable — bandaids stacked on a real bug were the wrong instinct, the proper fix is at the database transaction layer per CLAUDE.md.

## 1.5.8 - 2026-04-25

### Features

- [NEW FEATURE] `group_no_progress_detected` notification canonical wired through the full pipeline. The `SendStaleStepsNotification` listener now routes `StaleStepsDetected(reason='group_no_progress')` events (fired by `RecoverStaleStepsCommand --watchdog-progress` in step-dispatcher v1.11.6) to a Pushover canonical with severity=critical and a 10-minute throttle keyed by group. `NotificationMessageBuilder` carries the matching email + Pushover templates with copy-paste SQL diagnostic queries the operator can run to inspect the wedged group. New migration `2026_04_25_220000_seed_group_no_progress_notification` seeds the canonical row in production; `KraiteSeeder` carries the same content for fresh installs. `NotificationFactory::groupNoProgress()` state added so tests can seed it explicitly. Closes the operational-visibility hole identified during the 2026-04-25 wedge — without this listener mapping the watchdog event landed silently and the failure class it was built to catch could go unnoticed for hours.

## 1.5.7 - 2026-04-25

### Fixes

- [BUG FIX] `CreatePositionsCommand::attemptOpeningPositionsForAccount()` — added a command-entry idempotency guard that skips the dispatch when a non-terminal `PreparePositionsOpeningJob` step already exists for the account. The step-dispatcher framework guarantees ordering WITHIN a workflow tree but cannot prevent twin root workflows for the same domain entity. The 2026-04-25 17:33 incident proved this is the actual cause: two `PreparePositionsOpeningJob` rows dispatched 1s apart (operator manual artisan invocation racing the scheduled cron is the most plausible trigger; `schedule:work` lag and stale `withoutOverlapping` mutexes are also possible). The v1.5.6 `DispatchPositionSlotsJob` guard stops the consequence; this one stops the cause. Defense-in-depth pair.

## 1.5.6 - 2026-04-25

### Fixes

- [BUG FIX] `DispatchPositionSlotsJob::compute()` — added an idempotency guard that skips creating a `DispatchPositionJob` step for any 'new' position that already carries a non-terminal `DispatchPositionJob` step. Twin `PreparePositionsOpening` blocks for the same account (operator manual `kraite:cron-create-positions` racing the scheduled cron, recover-stale recovery re-running the orchestrator, etc.) used to produce one `DispatchPositionJob` per position per instance, with the loser racing the exchange and tripping the `total_limit_orders` cap mid-ladder — triggering `CancelPositionJob` at a realised loss. The 2026-04-25 17:33-17:34 cluster of 12 Failed steps (positions #241 + #242 closed at a loss) was exactly this race. Same shape as the orphan-recovery dedup landed in v1.5.5.

## 1.5.5 - 2026-04-25

### Features

- [NEW FEATURE] `CreatePositionsCommand::recoverOrphanPositionsForAccount()` — every `kraite:cron-create-positions` tick now self-heals positions stuck in `status='new'` with a token assigned but no live `DispatchPositionJob` step. The 2026-04-25 dispatcher drain operation deleted stale Pending rows; two positions (233, 235) survived the sweep but their follow-up `DispatchPositionJob` rows were swept along with the genuinely-stale work, leaving the positions stranded. Recovery runs unconditionally before the engine guards (slot-cap, directional, exchange cooldown) — orphans are slots already taken, recovering them doesn't compete with new opens. Idempotent: positions with any non-terminal `DispatchPositionJob` step are left alone; positions whose only step ended in a terminal state are treated as stranded and re-dispatched.

## 1.5.4 - 2026-04-25

### Fixes

- [BUG FIX] Eliminated the zombie-parent class of step-dispatcher wedges. 18 call sites that pre-set `'child_block_uuid' => Str::uuid()` at `Step::create()` time were rewritten to self-elect to parent mode inside `compute()` via `$this->step->child_block_uuid ?? $this->step->makeItAParent()`. Pre-setting commits the step to parent-mode before its compute() can decide whether children are actually being spawned; on any path that returns without creating children, the parent ends up with `child_block_uuid` pointing at an empty block, which `childStepsAreConcludedFromMap()` treats as not-concluded forever — wedging `transitionParentsToComplete()` and starving the dispatcher tick. Refactor covers cron commands (`CreatePositionsCommand`, `StoreAccountsBalancesCommand`, `SyncOrdersCommand`, `RefreshExchangeSymbolsCommand` × 3 sub-sites), the OrderObserver (4 sites), and orchestrators (`PreparePositionsOpeningJob`, `PrepareSyncOrdersJob`, `PrepareOrderCorrectionJob`, `PreparePositionReplacementJob`, `ApplyWapJob`, `ClosePositionJob`, `CancelPositionJob`, `SmartReplaceOrdersJob`, `DiscoverCMCTokensForOrphanedSymbolsJob`, `TouchTaapiDataForExchangeSymbolsJob`, `SyncLeverageBracketsJob` base + Bybit/Kucoin/Bitget overrides, `DispatchAccountBalancesJob`, `DispatchPositionSlotsJob`, `DispatchPositionJob` per-exchange variants × 4, `ReplacePositionOrdersJob` Binance/Bitget, `PlaceLimitOrdersJob`, `DispatchLimitOrdersJob`, `VerifyPositionExistsOnExchangeJob`, `CalculateWapAndModifyProfitOrderJob` follow-up WAP). `DispatchLimitOrdersJob` additionally guards `makeItAParent()` behind a `count > 0` check so an empty ladder doesn't elect to parent mode at all.

## 1.5.3 - 2026-04-24

### Features

- [NEW FEATURE] `Commands/Cronjobs/WatchPriceStreamCommand` (`kraite:watch-price-stream`) — external watchdog cron for the Binance mark-price daemon. Runs every minute, checks `MAX(mark_price_synced_at)` staleness against a 90s threshold, and bounces `kraite-ingestion-binance-prices` via `sudo -n supervisorctl restart` when the stream freezes. Includes a 120s cooldown gate (cache-backed) so overlapping boot windows and persistent upstream issues don't loop-restart the daemon. `--dry-run` mode available for ops.
- [NEW FEATURE] `Models/Kraite::timeframes()` static helper — single source for the global indicator/kline timeframe list that every consumer used to read as `$apiSystem->timeframes`. Memoised via `once()` so reads inside hot map closures (`HasTokenDiscovery::selectBestTokenByBtcBias`) and per-exchange iteration loops stay at one DB query per PHP process.
- [NEW FEATURE] `BacktestSimulator::simulate(..., ?Carbon $since = null)` — optional window start lets the admin UI and CLI limit the candle walk to a recent slice instead of the full symbol history.
- [NEW FEATURE] `BacktestTokenCommand` — added `--since` and `--candles_back` options mirroring the admin UI's window controls. The new `resolveWindowSince()` helper applies the same precedence (`--since` wins if both are set).

### Fixes

- [BUG FIX] Binance WebSocket price daemon — switched `BinanceApiClient::subscribeToStream` from the `/ws/<stream>` single-stream URL path to the `/stream?streams=<stream>` combined-stream path. Binance's `/ws/` endpoint silently stopped delivering frames for array streams (`!markPrice@arr@1s`) from our IP on 2026-04-24 — the handshake succeeded but zero data came through, and no `close` event ever fired so the existing reconnect logic never triggered. `StreamBinancePricesCommand` now unwraps the combined-stream envelope `{"stream": "...", "data": [...]}` before iterating the rows.
- [BUG FIX] Restored `src/helpers.php::cleanLogsFolder()` (and the `Illuminate\Support\Facades\File` import) — the deadcode audit on 2026-04-24 removed it but two callers inside `ConcludeSymbolsDirectionCommand::handle` and `FetchKlinesCommand::handle` (both inside the operator `--clean` paths) still reference it. The previous release would throw `Undefined function` when either command ran with `--clean`.

### Improvements

- [IMPROVED] `Abstracts/BaseWebsocketClient` — major resilience overhaul:
  - Idle-read watchdog: if no frame has been received for 30s the socket is force-closed, triggering the reconnect path. Catches the "TCP stays ESTABLISHED but server stops sending" zombie scenario that froze the Binance daemon for 14h on 2026-04-24.
  - Unbounded reconnect with capped exponential backoff (30s ceiling): dropped the prior 5-attempt cap so a supervised daemon never "gives up".
  - Heartbeat log every 60s (`connected_seconds`, `frames_received`, `seconds_since_last_frame`) so an empty daemon log file signals "daemon died" rather than "silent zombie".
  - Connection/close state transitions now log to the `jobs` channel.
  - Self-initiated 15-minute pong moved into the shared watchdog so it isn't leaking a new timer (pointing at a dead connection) on every reconnect.
- [IMPROVED] `Jobs/Models/ExchangeSymbol/FetchKlinesJob::upsertWithLock()` — reduced advisory-lock wait from 30s to 2s, and a lock miss now skips silently (with a debug log) instead of throwing. Overlapping bulk-fetch crons (`--only-active-positions` every 5 min + hourly per-timeframe ticks) used to fan out the same BTC/<timeframe> step into concurrent blocks; the loser of the `GET_LOCK` race would wait 11+ seconds and trip the 5s slow-query alarm into Pushover. The upsert is idempotent — both workers pull the identical recent-candle window — so dropping the duplicate loses nothing.
- [IMPROVED] Moved the indicator/kline timeframe list from `api_systems.timeframes` (per-exchange JSON) to `kraite.timeframes` (global singleton JSON). The per-exchange split was never exploited — all four exchange rows carried identical values. Migration `2026_04_24_000003_move_timeframes_from_api_systems_to_kraite` handles the hop (idempotent, with `Schema::hasColumn` guards on both directions), drops `api_systems.timeframes`, and seeds `kraite.timeframes` with `["1h","4h","12h","1d"]` from whatever the first non-null source row carried (falls back to the default set). Updated 6 consumers (`HasTokenDiscovery`, `ConcludeSymbolDirectionAtTimeframeJob`, `CalculateBtcCorrelationJob`, `CalculateBtcElasticityJob`, `ConcludeSymbolsDirectionCommand`, `FetchKlinesCommand`) plus the `ApiSystem` model cast, `ApiSystemFactory::exchange()` state, and the `KraiteSeeder` to use the new location.
- [IMPROVED] `ConcludeSymbolsDirectionCommand::handle()` — hoisted the timeframes-empty guard above the per-symbol `foreach` so a missing config short-circuits the entire batch instead of checking the invariant once per symbol.

## 1.5.2 - 2026-04-24

### Features

- [NEW FEATURE] Per-token ladder backtest pipeline. `Support/Backtest/BacktestSimulator` walks forward per (candle × direction) using the production market + ladder calculators (with `get_market_order_amount_divider($N) = 2^(N+1)` applied at the market-sizing boundary to match live sizing), classifies each outcome as `tp_hit_from_market_only` / `reboundable` / `stopped_out` / `non-reboundable`. Companion fetchers `BinanceVisionCandleFetcher` (free monthly ZIP archive, up to 24 months, idempotent upsert) + `TaapiCandlesFetcher` (recency top-up between Vision's last complete month and live) populate the `candles` table; `CandleCoverageVerifier` reports contiguity / hole-runs / staleness for operator visibility.
- [NEW FEATURE] `Commands/Backtest/BacktestTokenCommand` — `kraite:backtest-token` CLI wrapper mirroring the legacy `debug:analyse-non-reboundables` option surface (exchange_symbol_id / token+exchange / account defaults / tp/gap/sl overrides / multipliers / non_reboundable_only / days_to_ignore / single-candle mode). Registered in `CoreServiceProvider`.
- [NEW FEATURE] Direction-aware margin sizing. `Jobs/Atomic/Position/PreparePositionDataJob::calculateMarginWithSubscriptionCap(Account $account, string $direction)` reads `margin_percentage_long` for LONG positions and `margin_percentage_short` for SHORT — the desk can tune each side independently. Migration `split_margin_percentage_into_long_short` replaces the single `max_position_percentage` column with the two direction-aware columns (default 5.00 on both to preserve prior behaviour).
- [NEW FEATURE] Allow-list model for `Commands/Cronjobs/DisableVolatileTokensCommand`. Switched from deny-list (curated meme / speculative / structural-brittle / operator-excluded buckets) to `ALLOWED_TOKENS` (CMC top-75 ∩ Binance availability). Every `exchange_symbols` row whose token isn't on the allow-list gets `is_manually_enabled=false` across every api_system; strictly additive, never re-enables. Unknown listings stay disabled by default instead of auto-trading into the first ladder-math failure.

### Fixes

- [BUG FIX] `Support/TradingMappers/{Binance,Bybit,Kucoin,Bitget}TradingMapper::isNowDelisted()` — now accepts both `isDirty('delivery_ts_ms')` (pre-save / `saving()`-hook context) and `wasChanged('delivery_ts_ms')` (post-save / `saved()`-hook context) signals. Prior version relied only on `wasChanged`, which returns false during the `saving()` hook — the `is_marked_for_delisting` flag-flip path in `ExchangeSymbolObserver::saving()` never fired on the 2026-04-24 Binance delisting batch (DEGEN, B3, BOB, ZKJ, DAM, IR got pushover alerts but no flag persisted on their DB rows).

### Improvements

- [IMPROVED] `Support/TradingMappers/{Bybit,Kucoin,Bitget}TradingMapper::isNowDelisted()` — body simplified to `return $exchangeSymbol->delivery_ts_ms !== null;` after the `$changed` guard. The prior two-clause form was equivalent; with `$changed` already asserted, `$new !== null` captures the entire null-or-different case in one line.
- [IMPROVED] `Support/Backtest/BacktestSimulator::simulateOne()` — stop-loss price is now computed once at the rung-N promotion boundary and cached for the remainder of the walk-forward loop. Prior version recomputed `cumulativeQtyAtRung()` + `Kraite::calculateStopLossOrder()` on every candle after the last rung was touched; both results are invariant. Measurable savings on stopped-out legs.
- [IMPROVED] `Support/Backtest/CandleCoverageVerifier::INTERVAL_SECONDS` promoted from private to public const so both `TaapiCandlesFetcher::missingCandleCount()` and `BinanceVisionCandleFetcher::isMonthFullyCovered()` reference the single source of truth for timeframe-to-seconds mapping instead of re-declaring it locally.
- [IMPROVED] `Support/Backtest/TaapiCandlesFetcher::missingCandleCount()` — returns `['gap' => int, 'latest_ts' => ?int]` tuple so the `fetch()` skip-path reuses the already-resolved timestamp instead of firing a second `MAX(timestamp)` query.
- [IMPROVED] `Jobs/Atomic/Position/PreparePositionDataJob::compute()` — dropped narrative WHAT comments that restated the code verbatim. Kept the WHY comments explaining direction-aware sizing and leverage deferral.

### Removals (deadcode cleanup)

- [IMPROVED] Deleted zero-reference classes: `Kraite\Core\Models\AccountHistory`, `Kraite\Core\Models\Funding`, `Kraite\Core\Models\BinanceListenKey`, `Kraite\Core\Support\ModelChanges`, `Kraite\Core\Jobs\Debug\DispatchPlaceLimitOrderJob` (+ empty `Jobs/Debug/` directory).
- [IMPROVED] Removed unused globals from `src/helpers.php`: `try_times()`, `returnLadderedValue()` (duplicate of the `Kraite::returnLadderedValue()` static method), `summarize_model_attributes()`, `format_model_attributes()`, `deep_clean_json()`, `cleanLogsFolder()`. Dropped the now-unused `Illuminate\Database\Eloquent\Model` + `Illuminate\Support\Facades\File` imports.
- [IMPROVED] Removed `Trading/Concerns/HasComputationHelpers::testingMode()` — zero callers, and the config key it read (`kraite.testing.enabled`) was never defined → always resolved to null/false.
- [IMPROVED] Removed dead config keys `kraite.websocket` + `kraite.default_throttle_seconds` from `config/kraite.php`.

## 1.5.1 - 2026-04-23

### Fixes

- [BUG FIX] `src/helpers.php::truncate_decimal_string` — stopped stripping trailing zeros from pure-integer inputs. The unconditional `rtrim('0')` collapsed "1310" → "131" when precision was 0, silently dividing quantities by 10x and producing below-min-notional projections in the limit-ladder pre-gate. Root cause of the TAKE #151 / TAKE #146 / TURTLE #149 / USELESS #64 ladder-rejection failure class. Fix guards the rtrim with `str_contains($truncated, '.')` so only fractional tails get trimmed; integer values round-trip untouched.
- [BUG FIX] `Jobs/Atomic/ExchangeSymbol/QueryAndStoreSupportAndResistanceJob` — handles TAAPI's wrapped-array payload shape (`[{r1:..., s1:...}]`) in addition to the hypothetical flat shape; skips with a named `pivotpoints_payload_shape_unrecognised` reason when the payload can't be matched; no longer stamps `pivot_synced_at` on skip paths so half-populated zombie-state rows stop happening.
- [BUG FIX] `Jobs/Atomic/ExchangeSymbol/ConfirmPriceAlignmentWithDirectionJob` — both invalidation paths (missing indicator history, price-trend misalignment) now null the seven `pivot_*` columns + `pivot_synced_at` alongside `direction / indicators_values / indicators_timeframe`. Stale pivots never survive a direction clear.

### Improvements

- [IMPROVED] `Jobs/Atomic/Position/VerifyOrderNotionalForMarketOrderJob` — added `logFailureContext()` capturing the full input vector (margin, leverage, divider, notional, mark_price_fetched vs mark_price_in_memory, quantity_precision, tick_size, min_notional, percentage_gap_long/short, total_limit_orders, limit_quantity_multipliers, market_order_quantity, market_order_notional, ladder_error) on all three pre-gate throw paths (`unusable_quantity`, `market_notional_below_minimum`, `ladder_infeasible`). Forensic trail for transient reproducibility anomalies.
- [IMPROVED] `Jobs/Atomic/Order/SyncPositionOrdersJob` — removed SYNC-DEBUG info-level logging noise from `startOrFail` / `computeApiable`; kept only the error-level `[SYNC] order sync failed` log.
- [IMPROVED] `Jobs/Lifecycles/Order/PrepareSyncOrdersJob` — removed the `[PREPARE-SYNC-PROFILE]` timing-profile log and its unused `Log` facade import.
- [IMPROVED] `Commands/Cronjobs/DisableVolatileTokensCommand` — added BAS, ON, SYN, VELVET to `STRUCTURAL_BRITTLE_TOKENS` following 2026-04-23 ladder-rejection incidents.
- [IMPROVED] `src/helpers.php` — imported `Illuminate\Support\Facades\File` via a `use` statement so the procedural body reads `File::` instead of the FQCN.

## 1.5.0 - 2026-04-23

### Features

- [NEW FEATURE] Binance WebSocket price daemon. `kraite:stream-binance-prices` is a long-running process (supervisor-managed, NOT a cron) that subscribes to `!markPrice@arr@1s` and keeps `exchange_symbols.mark_price` + `mark_price_synced_at` fresh at 1 Hz for every Binance-listed symbol. Cross-exchange replication: one Binance tick also writes the same price to every matching (token+quote) row on Bybit / KuCoin / Bitget, using `token_mappers` overrides for naming divergence (BTC→XBT, 1000SATS→10000SATS, etc.). Architecture: `Abstracts/BaseWebsocketClient` (Ratchet/Pawl + React\\EventLoop, auto-pong, exponential-backoff reconnect, max 5 attempts), `Support/ApiClients/Websocket/BinanceApiClient`, `Support/Apis/Websocket/BinanceApi`, `Support/Proxies/ApiWebsocketProxy`. Raw chunked 500-row CASE/WHEN UPDATE bypasses Eloquent on the 1 Hz hot path. `gc_collect_cycles()` per batch for long-uptime stability.
- [NEW FEATURE] Pivot-based selection-phase S/R gate. Every candidate in `HasTokenDiscovery::selectBestTokenByBtcBias` + `selectBestTokenFallback` gets its base score multiplied by an S/R proximity factor computed from the symbol's stored pivot_r1/pivot_r3/pivot_s1/pivot_s3 columns, its live mark_price, and its concluded direction. Safe-zone candidates retain full score, penalty-band candidates fade linearly to zero (LONG approaching R1 / SHORT approaching S1), breakouts through R3 (LONG) or S3 (SHORT) are direction-aware (1.0 for continuation, 0.0 for against-direction). Soft by construction — a penalised candidate isn't filtered, it's just sorted to the bottom; if every candidate is penalised, the least-penalised wins. `Kraite\\Core\\Support\\SupportResistanceProximity::computeMultiplier()` holds the pure math; graceful-degrade to 1.0 on any missing input.
- [NEW FEATURE] `Indicators/RefreshData/PivotPointsIndicator` — `ValidationIndicator` that always returns true. Targets TAAPI's `pivotpoints` endpoint so the payload (r3/r2/r1/p/s1/s2/s3) lands in `indicator_histories` + mirrors into `exchange_symbols.indicators_values['pivotpoints']`. Always-true contract keeps pivot data from ever contributing to a direction vote or invalidating a timeframe. Seeded into `indicators` table by `KraiteSeeder::seedIndicators()`.
- [NEW FEATURE] `Jobs/Atomic/ExchangeSymbol/QueryAndStoreSupportAndResistanceJob` — runs at index 4 of `ConcludeSymbolDirectionAtTimeframeJob::createFinalizationSteps()`, parallel with `CopyDirectionToOtherExchangesJob`. Reads pivotpoints payload from `indicators_values` and copies the seven levels into dedicated DECIMAL(20,8) columns on `exchange_symbols`. Guards against direction having been cleared between scheduling and execution (e.g. `ConfirmPriceAlignmentWithDirectionJob` at index 3 detecting price trend misalignment).
- [NEW FEATURE] Migration `add_pivot_columns_to_exchange_symbols` — adds `pivot_r3`, `pivot_r2`, `pivot_r1`, `pivot_p`, `pivot_s1`, `pivot_s2`, `pivot_s3` DECIMAL(20,8) columns + `pivot_synced_at` timestamp.
- [NEW FEATURE] Migration `add_mark_price_synced_at_to_exchange_symbols` — nullable timestamp stamped by the WebSocket daemon for staleness detection.
- [NEW FEATURE] `Commands/Daemons/StreamBinancePricesCommand` — the daemon command itself, registered in `CoreServiceProvider`.
- [NEW FEATURE] Config knob `kraite.token_discovery.sr_safe_zone` (env `TOKEN_DISCOVERY_SR_SAFE_ZONE`, default 0.20) — width of the proximity penalty band as a fraction of the R1-S1 range. Tuneable without code changes.

### Improvements

- [IMPROVED] `ConcludeSymbolDirectionAtTimeframeJob` direction-invalidation paths now null the seven pivot columns + `pivot_synced_at` alongside `direction / indicators_values / indicators_timeframe / indicators_synced_at`. Applies to `handleInconclusiveTimeframe` (all-timeframes exhausted) and `handleDirectionChange` (path-invalid rejection). Stale pivots never survive a direction invalidation.
- [IMPROVED] `ConcludeSymbolsDirectionCommand --reset` flag also nulls the pivot columns for completeness.

## 1.4.9 - 2026-04-23

### Fixes

- [BUG FIX] `Jobs/Lifecycles/Position/{Binance,Bybit,Kucoin}/DispatchPositionJob` — swapped the opening-chain order so stop-loss is placed BEFORE take-profit. Prior ordering (TP → VerifyStillOpen → SL) opened a TOCTOU window on fast-moving tokens: the TP LIMIT could fill within milliseconds of the market entry, then the SL placement arrived at Binance after the position was already closed and was rejected with `-4509 "Time in Force GTE can only be used with open positions"`. The cascade unwound via cancel workflow and realized a loss (LAB #121, LAB #107 shared the race window, BSB #109 earlier). Placing the SL first turns the race into an invariant — SL is a conditional algo that doesn't fire at placement, so the position is protected before the TP ever has a chance to fill. Bitget is unaffected (single-call `PlacePositionTpslJob`).
- [BUG FIX] `Jobs/Atomic/Order/PlaceLimitOrderJob` — `startOrFail()` no longer rejects a retry that carries an `exchange_order_id`. Previously, a step that placed the order successfully on the first attempt then retried (stale recovery, doubleCheck blip, worker restart) bailed with "already placed" semantics → cascade → forced MARKET-CANCEL close at a worse price (LAB #107 realized loss). Now matches the idempotent-resume pattern `PlaceMarketOrderJob` already uses: `computeApiable()` short-circuits the `apiPlace()` call when the order already carries an `exchange_order_id` and lets `doubleCheck()` + `complete()` verify against the confirmed exchange state.
- [BUG FIX] `Jobs/Atomic/Position/CancelAlgoOpenOrdersJob` — both the select query and the pre-update query now filter `whereNotNull('exchange_order_id')`. A STOP-MARKET ghost row created before a failed `apiPlace()` (the `-4509` race on fast-trade tokens) was being picked up by the cancel loop, which then threw a secondary `ValidationException: options.algo id field is required` because there was nothing to cancel on the exchange. That secondary error masked the real upstream failure in the step log (LAB #121).

### Improvements

- [IMPROVED] `Jobs/Lifecycles/Position/{Binance,Bybit,Kucoin}/DispatchPositionJob` — dropped the `VerifyPositionStillOpenJob` pre-gate step that was guarding the TP→SL race. The SL-first reorder above makes the check structurally unnecessary. The `VerifyPositionStillOpenJob` classes remain in the tree as unreferenced orphans for now (no delete) in case we want them as defense-in-depth elsewhere.
- [IMPROVED] `Commands/Cronjobs/DisableVolatileTokensCommand` — added `OPERATOR_EXCLUDED_TOKENS` constant for tokens excluded by operator judgement (distinct from algorithm-detected brittles). Initial list: BEAT, CYS, GENIUS, PARTI, SKYAI, XPIN. Added BSB, IR, IRYS to `STRUCTURAL_BRITTLE_TOKENS` following the LAB-family fast-trade failures. Hourly sweep picks them up on every api_system.

### New atomic + lifecycle steps (orphaned by the SL-first reorder, kept for potential reuse)

- [NEW FEATURE] `Jobs/Atomic/Position/VerifyPositionStillOpenJob` + `Jobs/Lifecycles/Position/VerifyPositionStillOpenJob` — queries the exchange for the position's snapshot and throws if the position is no longer open. Originally designed as a TP→SL race pre-gate; no longer referenced by any exchange's `DispatchPositionJob` after the reorder. Left in the tree as a building block for future workflows that need the check.

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
