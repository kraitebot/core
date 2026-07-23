# Changelog

All notable changes to this project will be documented in this file.

## 1.80.2 - 2026-07-23

### Position-slot diagnostics

- [FIXED] Token assignment reports the actual number of temporary position
  slots removed after policy rejection instead of reporting zero afterward.
- [UNCHANGED] Rejected slots are still removed inside token selection; token
  eligibility and position-opening behavior are unchanged.

### Verification

- [VERIFIED] The full ingestion suite passes: 3,091 tests / 9,874 assertions;
  static analysis, formatting, and PHP syntax checks pass.

## 1.80.1 - 2026-07-23

### Backtest history depth

- [FIXED] Fresh, contiguous candles count as complete only when they also
  reach the history boundary requested by the operator.
- [FIXED] The pre-fetch and post-fetch coverage verdicts now use the same
  requested-window rule, so thin history remains visibly advisory.

### Verification

- [VERIFIED] Focused coverage passes: 15 tests / 58 assertions; changed core
  files pass static analysis and formatting.

## 1.80.0 - 2026-07-23

### Unified business rules

- [IMPROVED] Tradability, token candidate selection, order lifecycle routing,
  system-health checks, order states, and backtest timeframes now have one
  domain owner instead of repeated rules across jobs, observers, and commands.
- [IMPROVED] Repeated API-system, indicator, order, and position filters use
  named model scopes so their business meaning stays consistent.
- [CONFIG] Indicator retry and per-symbol leverage-bracket batch limits are
  explicit configuration values instead of embedded numbers.

### Dormant behavior removed

- [CHANGED] Indicator histories are retained; the obsolete cleanup workflow
  and its constructor flag are removed.
- [CHANGED] Inactive direction and routine position-opened/closed notification
  senders are removed. High-profit and safety notifications remain active.

### Verification

- [VERIFIED] Ingestion coverage passes: 205 tests / 531 assertions; static
  analysis reports zero errors.

## 1.79.2 - 2026-07-23

### Immediate backtesting approval queue

- [ADDED] Binance symbols can be selected when backtesting approval is the
  final operator action before they become tradeable.
- [SAFETY] The queue reuses every live new-position gate and excludes only the
  approval and manual-enablement changes performed by Approve.

## 1.79.1 - 2026-07-23

### Immediate trading stop

- [FIXED] Market entry repeats full account trading readiness immediately
  before creating new exchange exposure.
- [SAFETY] An entry already accepted by the exchange keeps reconciling after
  trading is disabled, so existing exposure is never abandoned.

## 1.79.0 - 2026-07-22

### Exchange processing control

- [ADDED] API systems have an activation switch that removes disabled
  exchanges from account, symbol, health, recovery, and financial processing.
- [SECURITY] Already-queued API jobs stop before making a request when their
  API system is disabled.
- [CHANGED] Binance is the only active exchange; Bybit, KuCoin, Bitget, and
  future exchange rows remain stored but inactive.

### Single-server production

- [CHANGED] The fleet and queue topology now target the private-use `kraite`
  host at `135.181.93.226`.

## 1.78.1 - 2026-07-21

### Improvements

- [IMPROVED] Exchange access now enters through typed account, system, symbol,
  order, and position boundaries while legacy model methods remain compatible.
- [IMPROVED] BSCS opening, position-cap, leverage, margin, and dashboard state
  share one account-scoped facade and one loaded market-regime snapshot.
- [IMPROVED] Token selection and health/drift remediation now live in dedicated
  domains instead of model traits and console commands.

### Registration inspection

- [NEW FEATURE] Balance discovery and existing-position/order inspection can
  run on the high-priority trading worker lane during public registration.

## 1.78.0 - 2026-07-21

### Registration welcome

- [ADDED] Successful public registration sends one mail-only welcome per
  trader with start-cycle guidance, existing-activity context, and explicit
  trading-risk disclosure.
- [FIXED] Welcome delivery and after-commit scheduling failures cannot roll
  back registration, and re-enabling an existing account does not send a
  misleading onboarding message.

### Indicator pacing

- [CHANGED] The default TAAPI Expert profile runs at 68 requests per 15
  seconds with 221 ms pacing, approximately 10% below the provider ceiling.

## 1.77.3 - 2026-07-21

### Bitget lifecycle reliability

- [FIXED] Unified full-position TP and SL now mirror Bitget's single remote
  strategy while preserving two logical local protection rows through sync,
  drift, recovery, replacement, and cancellation.
- [FIXED] Two confirmed-flat snapshots complete manual-close reconciliation
  without sending a redundant Unified flash-close request.
- [FIXED] Already-absent Unified orders and positions reconcile idempotently,
  and rejected protection or ladder orders enter the normal replacement path.
- [FIXED] Unchanged polling responses no longer refresh order timestamps and
  prevent stable orders from reaching drift inspection.

## 1.77.2 - 2026-07-20

### Order synchronization

- [FIXED] Polling queries only working exchange orders; terminal local history
  no longer creates repeated not-found traffic.
- [FIXED] Binance, Bybit, and KuCoin regenerate signed authentication before
  every retry attempt, preventing backoff from expiring the original request.

## 1.77.1 - 2026-07-20

### Deployment safety

- [FIXED] Cooldown waits for executable leaf steps across both prefixes while
  excluding populated workflow parents, preventing a dispatch-pause deadlock
  without allowing real in-flight trading work to be abandoned.

## 1.77.0 - 2026-07-20

### Bitget full trading compatibility

- [ADDED] Unified v3 trading for one-way and hedge accounts: entries, limits,
  trigger orders, independent TP/SL strategies, modification, cancellation,
  close, fills, PnL history, leverage, margin context, sync, drift, and recovery.
- [FIXED] Classic one-way closing fills keep their native execution side;
  hedge-only side normalization no longer inverts the recorded close.
- [CHANGED] Exchange hedge mode changes request/response shape only. Kraite
  still enforces one non-terminal position per account and symbol.

### Dispatcher workload and recovery

- [CHANGED] Hourly catalogue refresh creates zero leverage-bracket steps; an
  explicit `--with-brackets` run performs the full six-hour sweep.
- [CHANGED] Child workflow creation and indicator finalization are atomic and
  idempotent, including rollback-safe symbol conclusions.
- [FIXED] Stale copied indicator rows are suppressed only while their Binance
  source is fresh; genuine source staleness still alerts.
- [CHANGED] Connectivity and position-safety work uses the low-latency priority
  path, and Bitget orphan checks acquire regular plus strategy orders atomically.

## 1.76.0 - 2026-07-20

### Bitget unified accounts (Phase 1 — onboarding + reads)

- [ADDED] Automatic Bitget account-mode detection (classic vs unified/UTA)
  via a pre-auth classic probe (error 40085), cached on
  `accounts.bitget_account_mode`.
- [ADDED] Mode-aware private reads: balance, positions, open orders, and
  API-key permissions route to the Bitget v3 API for unified accounts.
- [ADDED] Unified withdrawal-safety check on the v3 key-permission scopes
  (`uta_trade` / `uta_mgt` safe list, fail-closed on anything else).
- [ADDED] Trading-readiness gate refuses unified Bitget accounts until the
  v3 order surface ships.

### Own-activity protection

- [ADDED] `ForeignActivityDetector` + `AccountActivityFlagSync`: the
  `allow_other_positions` / `allow_other_orders` flags now track live
  exchange evidence in the system-health watchdog (before any orphan
  cleanup) and in the position-opening chain (`SyncAccountActivityFlagsJob`,
  before sizing). Protection tightens on any evidence; loosens only on
  reliable ticks.
- [CHANGED] `OrphanReconciler`: shared accounts now flat-close provable
  Kraite leftovers (recently-closed position keys) while ignoring
  everything else as the user's.
- [CHANGED] Binance ghost-algo scrub is skipped on accounts that allow user
  orders.

### Registration connectivity UX

- [ADDED] Probe failures translate to plain user-facing messages (named
  missing permission, IP not whitelisted, key rejected; Binance combined).
- [CHANGED] `StepRouter` exempts diagnostic connectivity steps from the
  clean-worker ban veto; a dead connectivity parent now reports the run
  complete + failed instead of hanging pollers.

### Fixes

- [FIXED] `BitgetThrottler` crashed on warm Redis reservation keys — the
  framework returns cached integers as numeric strings; now normalised.
- [FIXED] The engine credentials accessor never exposed Bitget/KuCoin admin
  keys, so system-level private calls on those exchanges could not
  authenticate.

## 1.75.0 - 2026-07-19

### Mobile API authentication

- [ADDED] Shared schema now stores expiring, ability-scoped personal access
  tokens for the first-party mobile API.

## 1.74.0 - 2026-07-19

### Bitget position opening

- [FIXED] Each Bitget opening reads the live position mode for its selected
  futures product before any mutating request.
- [FIXED] Hedge orders use Bitget V2 buy or sell and open or close semantics
  without sending the unsupported `posSide` field. One-way closing orders
  remain reduce-only.
- [FIXED] Combined position TP and SL orders persist their local identities
  before placement and resume safely after worker retries.
- [FIXED] Signed query parameters are sorted before signing, retries use the
  current exchange error code, and position-mode error `43075` is handled.

### Bitget request pacing

- [FIXED] Private requests coordinate both by API key and by a conservative
  server-shared endpoint budget.
- [FIXED] All Bitget traffic observes the 6,000-request-per-minute source-IP
  ceiling. A 429 pauses the server for five minutes unless Bitget asks for
  longer.

### API-key safety

- [ADDED] Binance and Bitget connectivity checks report whether withdrawal
  permission is enabled so onboarding can fail closed before activation.

## 1.73.7 - 2026-07-18

### Bitget request pacing

- [FIXED] Every real Bitget HTTP attempt now reserves an endpoint-specific
  rate-limit slot, including multi-call jobs and internal client retries.
- [FIXED] Public requests coordinate by source IP while signed requests
  coordinate by API key. Signed retries receive a fresh timestamp and
  signature after waiting.

### Token selection

- [FIXED] Fast-track re-entry considers only cleanly `closed` positions.
  `cancelled` and `failed` histories cannot bypass normal scoring.

## 1.73.6 - 2026-07-17

### Release reliability

- [FIXED] Core CI now checks out its local package dependencies and resolves
  them through explicit path repositories before linting.

## 1.73.5 - 2026-07-17

### Safe local production clones

- [FIXED] Production snapshots now replace every imported user password with
  the configured local clone password and clear persistent login tokens.
  Cloned admin data remains usable without knowing any production password.

## 1.73.4 - 2026-07-16

### Production alert quality

- [FIXED] Successful WAP application uses normal Pushover priority instead
  of bypassing quiet hours.
- [FIXED] Balance and indicator freshness checks observe a bounded
  post-warmup recovery window while independent health checks stay active.
- [FIXED] An indicator symbol is not reported stale while its own recent
  producer workflow is active. Terminal and abandoned old work cannot hide
  genuine staleness.

### Tests

- [ADDED] Regression coverage for WAP delivery priority, post-warmup health
  behavior, active query and conclusion workflows, terminal steps, and
  abandoned steps.

## 1.73.3 - 2026-07-16

### Bitget USDC futures

- [FIXED] Very small contract tick sizes retain their full precision instead
  of being stored as zero. The migration also repairs already-truncated rows
  from their preserved exchange metadata.

## 1.73.2 - 2026-07-16

### Deployment safety

- [FIXED] Fresh migrations also skip slow-query notification before the
  Kraite settings table itself exists.

## 1.73.1 - 2026-07-16

### Deployment safety

- [FIXED] Slow schema migrations are audited without attempting an admin
  notification before the Kraite singleton has been seeded.

## 1.73.0 - 2026-07-16

### Bitget USDC futures

- [ADDED] **Bitget now supports USDC perpetuals across discovery and the
  complete trading lifecycle.** Catalogue refresh atomically merges
  `USDT-FUTURES` and `USDC-FUTURES`; balances, positions, orders,
  prices, leverage, margin, protection, recovery, cancellation, and close
  derive the correct product and margin coin from account or symbol quote.
- [FIXED] **USDT and USDC contracts remain separate trading identities.**
  Selection is constrained to the account's exchange and quote, metadata is
  preserved per contract, and missing or unsupported quotes fail explicitly.
- [FIXED] **Bitget one-way order correction uses the exchange's `BOTH`
  position slot,** while hedge accounts continue to match LONG or SHORT.

### Local snapshot safety

- [ADDED] **`kraite:freeze` fully isolates local production-shaped data.**
  Schedules, jobs, dispatchers, WebSockets, mail, notifications, inbound
  external traffic, and outbound HTTP stop while local UI and data edits
  remain available.
- [ADDED] **`kraite:clone` imports a production snapshot only while
  frozen and only with exact migration parity.** Nine high-volume local
  tables remain untouched, credentials stay out of process arguments, and
  temporary dumps are removed from both hosts.
- [ADDED] **`kraite:unfreeze` refuses dirty trading state.** Interactive
  cleanup or `--force` clears positions, orders, dispatcher state, logs,
  API audit data, and queued jobs before automation can resume.

### Tests

- [ADDED] End-to-end USDC lifecycle, atomic catalogue, unsupported-quote,
  full-freeze traffic, destructive-unfreeze, clone parity, secure transfer,
  and schema-compatibility regression coverage.
- [VERIFIED] Full ingestion suite, Step Dispatcher feature suite, Pint,
  Rector, PHPStan, and type coverage pass.

## 1.72.1 - 2026-07-16

### Bitget position opening

- [FIXED] **Bitget orders now preserve the account's selected margin mode.** Regular and plan orders use crossed or isolated mode from the account instead of forcing crossed mode.
- [FIXED] **Bitget position protection targets the correct slot in both account modes.** TP/SL requests send long/short for hedge positions and buy/sell for one-way positions.
- [FIXED] **HTTP-success vendor errors can no longer become successful requests or empty snapshots.** Bitget responses require vendor code `00000` before mapping and success logging; retries apply the same validation and retain the final failed response for diagnosis.

### Tests

- [ADDED] TDD coverage for the complete Bitget opening chain, margin modes, hedge/one-way TP/SL routing, malformed and failed HTTP-success envelopes, retry outcomes, and downstream state preservation.

## 1.72.0 - 2026-07-15

### Exchange listing lifecycle

- [IMPROVED] **Delisting warnings and terminal exchange removal now carry separate truth.** A warning marker immediately blocks new openings, while a reached delivery timestamp proves terminal removal. Binance and Bitget full-catalogue absence becomes terminal; Bybit and KuCoin active-only absence remains warning-only until an explicit terminal exchange response.
- [IMPROVED] **Returning active symbols recover automatic listing state.** Catalogue refresh clears the warning and terminal timestamp and restores Binance same-asset overlap without changing manual or unrelated safety gates.
- [IMPROVED] **Existing exposure remains operational after removal.** Price and kline monitoring retain terminal symbols while an open position exists; idle terminal rows leave operational workloads.

### Trading gates

- [IMPROVED] **Manual enablement is exclusively sysadmin-owned.** Opening failures and hourly allow-list enforcement use a separate reasoned system block; price divergence uses the independent alignment gate. Automated flows no longer rewrite `is_manually_enabled`.
- [FIXED] **Atomic price comparison rechecks warning-only Binance references at execution time,** closing the queue race between parent selection and the live comparison.
- [FIXED] **Model and query tradeability agree on price alignment,** so operator-facing state cannot report a misaligned symbol as tradeable.
- [FIXED] **Kline fan-out jobs queued before this release remain deserializable** after the new protected-reference payload field is deployed.

### Tests

- [ADDED] Heavy TDD coverage for exchange catalogue semantics, relisting, system/manual gate separation, active-exposure monitoring, execution-time price-reference validation, and legacy queue payload compatibility.

## 1.71.0 - 2026-07-15

### Position safety

- [IMPROVED] **Exchange position truth is validated before any missing-position action.** Binance, Bitget, Bybit, and KuCoin responses must have a successful vendor envelope, valid normalized rows, and matching raw/open row counts. HTTP-success vendor errors preserve the last trusted snapshot instead of masquerading as an empty account.
- [IMPROVED] **REST flat detection now requires two exact reads 20 seconds apart.** Replacement, WAP, partial-fill quantity sync, drift follow-up, and disaster recovery match symbol plus logical direction across hedge and one-way accounts. Only confirmed absence can cancel Kraite-owned opening LIMITs; reappearance, malformed data, and opposite-side rows preserve every order.
- [IMPROVED] **Manual-close protection remains immediate and exchange-neutral.** The User Data Stream zero-quantity event reuses the shared high-priority opening-order cancellation contract while the normal replacement workflow retains final lifecycle ownership.

### Recovery

- [FIXED] **Recovery cannot delete ownership before exchange cleanup succeeds.** Normal recovery confirms absence twice and synchronously cancels opening LIMITs before its terminal write. `--override` cancels those orders before deleting local positions, orders, and steps. Cancellation failure preserves ownership and reference state; dry-run performs no exchange cancellation.
- [FIXED] **Drift API failures no longer become false DB-only positions.** Invalid position responses produce an API-error report with no position classification; the drift command remains alert-only and schedules an independent confirmation only for a valid DB-only result.

### Tests

- [ADDED] TDD coverage for all four exchange envelopes, exact hedge/one-way matching, trusted-snapshot preservation, replacement confirmation, WAP, partial-fill sync, drift, recovery/override failure safety, and User Data Stream deduplication.

## 1.70.2 - 2026-07-15

### Bug fixes

- [FIXED] **The incident narrator no longer starts project development MCP servers in production.** Its Claude subprocess runs from the system temporary directory, so the tracked local Laravel Boost configuration is outside Claude's project discovery path while the incident prompt, user authentication, timeout, and fail-safe behavior remain unchanged.

### Tests

- [ADDED] Regression coverage proves the narrator subprocess uses the isolated working directory.

## 1.70.1 - 2026-07-15

### Bug fixes

- [FIXED] **A manual exchange close now sheds residual DCA LIMIT risk immediately.** A zero-quantity Binance account update starts an independent high-priority cancellation for the matching position's live opening LIMIT orders while the normal replacement workflow continues to own final reconciliation. Hedge LONG/SHORT and one-way BOTH rows are matched explicitly; duplicate frames and concurrent replacement work are deduplicated. TP and SL protection is not cancelled by this safety action.
- [FIXED] **The pre-entry exchange guard blocks an already-open pair in every direction.** LONG, SHORT, and one-way BOTH snapshot keys all prevent either slot direction from opening the same trading pair.

### Tests

- [ADDED] End-to-end stream coverage for flat/non-flat updates, hedge and one-way matching, duplicate frames, replacement coexistence, high-priority routing, and LIMIT-only cancellation. Existing token selection and lifecycle cancellation behavior are pinned by regression tests.

## 1.70.0 - 2026-07-14

### Architecture

- [IMPROVED] **Every remaining orchestrator now builds its child chain under one locked transaction.** Parent election and child insertion share a row lock, partial builds roll back, and stale concurrent job instances converge on the existing block instead of duplicating exchange-facing work. Step ownership and live-state filters now use the reusable relational scopes shipped by `brunocfalcao/step-dispatcher` 1.18.0.
- [IMPROVED] **Workflow dispatches persist their owning account or position at creation time.** WAP, replacement, close, sync, opening, recovery, and observer dedupe paths use indexed `relatable_type` / `relatable_id` lookups instead of repeated JSON scans. Production was audited before removing the legacy argument fallback.

### Bug fixes

- [FIXED] **Delisted symbols remain operationally monitored while they carry an open position.** Idle delisted rows stay excluded from new-trading candidates and atomic price comparison, but active exposure continues through mark-price health and renamed-contract alignment checks, including a delisted Binance sibling when it is the required reference.
- [FIXED] **`waping` now owns the database open-position slot.** The generated `positions.is_open` constraint matches `Position::openedStatuses()`, preventing a scheduler race from inserting a second same-account, same-symbol, same-direction position during WAP.
- [FIXED] **Trading readiness is centralized and complete.** Account and user activation, user pause switch, subscription window, free-tier state, and the designated account on one-account tiers are evaluated by `Account::isReadyToTrade()` everywhere position opening is considered.
- [FIXED] **Trial and pause timing no longer strand valid subscriptions.** Starting a trial establishes its first renewal anchor, legacy missing anchors are backfilled, free tiers remain active without an anchor, and sub-day pauses extend renewal by their exact duration.
- [FIXED] **Partial NOWPayments updates credit only the newly settled delta.** Payments track cumulative `credited_amount`, previously credited rows are backfilled, read endpoints use retry-safe HTTP behavior, and the gateway base URL follows application configuration.
- [FIXED] **Direction conclusion cannot overlap and its destructive reset is local-only.** A distributed lock serializes runs; `--clean` is refused outside local/testing.
- [FIXED] **Queue-depth health reads the physical Horizon lanes workers actually consume.** Each logical threshold sums its routed per-host queue set instead of inspecting an orphan logical Redis list.

### Tests

- [ADDED] Regression coverage for stale-orchestrator concurrency, relational workflow scopes, open-slot uniqueness during WAP, delisted active-exposure monitoring, physical Horizon queue depth, billing readiness and timing, and destructive command safety.

## 1.69.1 - 2026-07-14

### Bug fixes

- [FIXED] **A safe pre-entry rejection no longer becomes a failed position when there are no orders to synchronize.** ETCUSDT position #763 correctly stopped before market placement because its projected margin was below the configured minimum, but the cleanup sync treated the intentionally empty order set as a failure. Only an empty sync after the workflow has entered `cancelling` becomes a skipped no-op; active/opening positions with no syncable protection still fail loud, and a total exchange-query failure still retries and alerts.
- [FIXED] **Price-alignment checks no longer compare a renamed token with a stale delisted Binance sibling.** DATAUSDT on Tyche was compared with delisted IPUSDT and disabled by a false threshold mismatch. Both parent candidate selection and the atomic lookup now require a non-delisted Binance reference. The notification describes contract-unit, rebrand, and genuine venue-divergence possibilities instead of claiming one diagnosis. This gate affects new-position eligibility only; active positions remain selected by position/order state for sync, WAP, protection, and closure.

## 1.69.0 - 2026-07-14

### Bug fixes

- [FIXED] **Stuck WAP: Binance `avgPrice` omission crashed the take-profit resize.** Binance omits `avgPrice` on order-modify (and query) responses for never-filled orders; the unguarded read crashed the WAP TP resize *after* the exchange had already applied it, the failed step never recorded `was_waped`, and the order observer's correction then reverted the resize — leaving position #394 FILUSDT with a take-profit covering 47.3 against a 141.9 exchange position, permanently. The modify + query response mappers are now null-safe on `avgPrice` (matching the cancel-mapper guard from the SKYUSDT incident). See deploy-notes Entry 104.
- [FIXED] **Recovery fan-out declared success over failed accounts.** `terminalStepStates()` includes Failed/Stopped, so "all settled" was read as "all succeeded": a fan-out where per-account jobs failed still returned SUCCESS, un-froze trading, and sent a "recovery completed" notification over a database that was never rebuilt. The verdict now counts failed terminal states and returns FAILURE (trading stays frozen for operator review); a failed step's exception payload is never read as zero-count success; and the fan-out path is wrapped so an exception can't leave the fleet frozen with no report.

### Features

- [ADDED] **Stuck-WAP self-heal (drift spotter Scope 2b).** Every 5 minutes, each `active` position whose FILLED entry ladder (MARKET + DCA LIMITs, at the symbol's quantity precision) exceeds its resting take-profit quantity gets `ApplyWapJob` re-dispatched — the observer's exact dedupe (position-row lock; skip when a WAP step is pending/dispatched/running; terminal Failed steps do NOT block). Skips mid-flight statuses, no quiet-window gate (which had hidden busy positions), runs even while the bot is cooled, `--skip-wap-heal` kill switch, one `position_wap_self_healed` pushover per heal.
- [ADDED] **Disaster recovery fleet fan-out.** `kraite:recover-positions` defaults to dispatching one per-account recovery job across the workers (shared API throttle + settle-poll), collapsing the old single-box freeze; `--inline` keeps the sequential path (dry-run always forces it).

## 1.68.0 - 2026-07-13

### Improvements

- [IMPROVED] **Audit trail no longer floods on recomputed indicator analytics.** ExchangeSymbol logged every attribute change, and the indicator refresh cycle rewrites BTC-correlation, elasticity, pivot levels, and indicator sync markers across all ~2,250 symbols each cycle — 98% of model_logs (~260K rows/day) was this recompute churn, not trade history. `ExchangeSymbol::$skipsLogging` now excludes those 17 columns from the audit trail (honoured by ModelLogObserver's per-model blacklist). The trading-relevant snapshot is already captured per-trade on `positions.indicators_values` at open, so no decision context is lost. Write rate drops ~95%; Position/Order/Account audit and symbol direction/tradability flags stay fully logged. See deploy-notes Entry 103.

## 1.67.1 - 2026-07-13

### Bug fixes

- [FIXED] **Aborted-open unwind no longer crashes on an empty close response.** When a position is refused at open before any order reaches the exchange (e.g. the market slice sizes below the exchange minimum notional), the compensating cancel/rollback calls `Position::apiClose()`, which for a never-opened position returns an empty "nothing to close" `ApiResponse`. That value object typed `$response` non-nullable while its own constructor advertised `?Response = null`, so the empty-response branch threw `TypeError: Cannot assign null to property ApiResponse::$response` and flipped the position to `failed` with a false `position_opening_failed` alarm. `ApiResponse::$response` is now nullable; the sole consumer reads `->result`, never `->response`, so the widening moves no crash downstream. (LTCUSDT #736, deploy-notes Entry 102.)

## 1.67.0 - 2026-07-13

### Features

- [NEW FEATURE] **Trading money-guard (CheckDrifts Scopes 3+4).** The 5-minute safety cron gains a deterministic cooling contract: a broken active-position structure, a burst of freshly-failed positions, a storm of Failed trading steps, or rapid-fire exchange errors in the log flips `allow_opening_positions=false` (global open halt — existing positions keep trading/closing), writes one incident file under `monitoring/` + an `OPEN-INCIDENT` marker, and fires one direct Pushover that bypasses the template system. Alarm-once latch: while cooled, the cooling detectors skip. Thresholds live in `kraite.guard.*` config. Positions whose TP/SL already FILLED/TRIGGERED are exempt from the structure check (position #503 false-cool, 2026-07-12).
- [NEW FEATURE] **`kraite:monitor-narrate` — Haiku incident narrator.** Documentation-only layer: enriches an open, un-narrated incident file into a readable narrative via the Claude CLI (argv-array invocation, no shell). Never detects, never decides; CLI absent/failed → the deterministic stub stands alone.
- [NEW FEATURE] **Black subscription plan seeded.** Seeder now seeds `basic` / `unlimited` / `black` (invite-only, free forever, uncapped) and no longer resurrects the renamed `starter` row on every seed run.

### Improvements

- [IMPROVED] **`allow_other_positions` now forces margin sizing onto available-balance.** `Account::balanceForTradingSnapshotKey()` returns `available-balance` whenever the account allows the user's own positions — capital locked in personal trades is never counted when sizing Kraite's positions. Regression-tested (ingestion `AccountBalanceForTradingTest`).
- [IMPROVED] Billing-columns migration backfills the entry tier by either canonical (`starter`/`basic`) so fresh installs seed rates correctly after the seeder rename.

## 1.66.0 - 2026-07-12

Second external SME code-review batch (GPT-5.6 "Sol Ultra"), adjudicated adversarially: 12 findings implemented, 4 discarded with evidence. Every fix carries a regression test; the money-path ones are the headline. See deploy-notes Entry 100.

### Bug fixes — workflow engine

- [FIXED] **Retried trading orchestrators could duplicate a partially-built child chain (duplicate market entries).** `child_block_uuid` is persisted the moment an orchestrator elects to parent mode, then child steps are inserted one at a time. A transient DB error mid-build (classified retryable → parent back to Pending) or `steps:recover-stale` reclaiming a settled-tree zombie re-ran `compute()` against the same block and re-inserted children 1..N-1 at the same `(block_uuid, index)` — no unique constraint — making duplicate exchange-facing steps dispatchable. New `BaseQueueableJob::buildChildChainOnce()` wraps the build in a transaction (partial build rolls back) plus a populated-block guard (settled-tree rerun no-ops). Applied to all six money-path orchestrators: `DispatchPositionJob` (Binance/Bybit/Kucoin/Bitget), `CancelPositionJob`, `ApplyWapJob`.
- [FIXED] **Ladder step-insertion failures were swallowed and reported as success.** `DispatchLimitOrdersJob` created every Order row, then caught each per-rung `Step::create()` failure and continued — leaving a phantom `NEW` order with no placement step and no `exchange_order_id`, while the response claimed success and the promised resolve-exception path never fired. Order rows and placement steps now build together inside `buildChildChainOnce()`: a mid-build failure rolls back both and reaches the exception handler.
- [FIXED] **Opening failures before the `opening` transition left positions stuck in `new`, re-selected every cron cycle.** The opening chain's resolve-exception path (`CancelPositionJob`) omitted `new` from its first status step's `onlyFromStatus`, so a failure in verify-pair / margin-mode (which run while status is still `new`) no-opped the cancel and the position stayed `new` — an infinite open-fail-"cancel" loop. `new` is now claimable through `cancelling → cancelled`; the exchange-facing children no-op naturally on a positionless `new`.

### Bug fixes — orders & corrections

- [FIXED] **Concurrent recreation could place two replacements for one cancelled order.** The lineage lookup (SELECT latest replacement) and `Order::create()` had no lock or unique constraint between them. Two workflows for the same cancelled order both saw no replacement and both `apiPlace()`d. Now serialized on the cancelled order row (`lockForUpdate` in a transaction, loser adopts the winner's row) plus a **new migration** making `orders.recreated_from_order_id` unique (prod verified zero duplicate parents before the swap).
- [FIXED] **Bitget order-correction dedupe queried the wrong class.** The dedupe searched the base `PrepareOrderCorrectionJob`, but the insert stored the `JobProxy`-resolved class — for Bitget the override — so a pending Bitget correction was invisible and every observer cycle on a still-drifted order enqueued another chain (racing `place-pos-tpsl` overwrites on live TP/SL). Dedupe now searches the resolved class.
- [FIXED] **Correction dedupe was a non-atomic SELECT-then-INSERT.** `checkForOrderModification` was the lone observer dispatch path without the `DB::transaction` + `lockForUpdate` + status re-check its four siblings (close / replacement / WAP / partial-fill) all use. Two concurrent observer fires could both enqueue a correction. Now matches the sibling discipline.
- [FIXED] **Bitget paired TP/SL overwrite could restore a cancelled historical sibling.** `place-pos-tpsl` overwrites BOTH legs atomically; the sibling-selection helpers (`ModifyAlgoOrderJob`, Bitget WAP) picked the oldest row with no status filter, so a stale CANCELLED trigger silently rewrote the live opposite leg. Both helpers now select the newest live (`activeOnExchange`) sibling only.

### Bug fixes — WAP & close

- [FIXED] **WAP follow-up acknowledged fills before the follow-up step was durably created.** `CalculateWapAndModifyProfitOrderJob::complete()` bulk-bumped unacked FILLED LIMITs to `reference_status=FILLED` and then created the follow-up `ApplyWap` step — the comment promised one transaction, the code had none. An ack that outlived a failed step insert permanently silenced those fills (the sync recovery sweep only re-discovers UNACKED fills), leaving the TP sized for the earlier fill set. Now wrapped in `DB::transaction`.
- [FIXED] **TP-filled verification aborted WAP without persisting the fill or starting closure.** `VerifyIfTPIsFilledJob` threw on exchange-reported FILLED, discarding the definitive fill it had just fetched; the position reverted `waping → active`, a phantom-live position if WS/sync reconciliation was degraded. It now persists `FILLED` first, which fires the observer's locked, deduped close dispatch (claims `closing`); the WAP resolver's revert-to-active then no-ops on its `waping`-only guard.
- [FIXED] **Bitget WAP could send the success notification twice per lifecycle.** The Bitget variant notifies inline from `computeApiable` (added after the 2026-05-02 production silence) and again via inherited `complete()`; dedupe depended entirely on the 30s notification-side cache throttle. A per-instance send-once latch now makes one-notification-per-WAP a property of the job, first call wins (accurate "old" values). Both historically-motivated call sites kept.

### Removed

- [REMOVED] **Dead `verifyPrice` flag on the atomic close path.** `ClosePositionAtomicallyJob` declared and passed `verifyPrice` end-to-end but never read it — vestigial from the Martingalian rebrand; cancel and normal close were always identical despite the lifecycle API and comments advertising a price gate. Flag removed everywhere (atomic job, lifecycle `withVerifyPrice()`, `CancelPositionJob` call site) — zero behaviour change, no false contract.

### Security (carried from the untagged 1.65.x follow-up)

- [FIXED] **`AccountPolicy::operate` accepts any `Authenticatable`.** The concrete core-`User` typehint made the Gate throw a `TypeError` for consuming apps (admin) that authenticate their own User class against the shared users table, instead of authorizing them.

## 1.65.0 - 2026-07-11

### Bug fixes

- [FIXED] **Direction conclusion no longer blends indicators from different runs (signal integrity).** `ConcludeSymbolDirectionAtTimeframeJob` selected `MAX(timestamp)` per indicator independently, and `indicator_histories.timestamp` is the wall-clock write time — so a partial TAAPI bulk response (construct-level errors on a 200, which lets the query step COMPLETE rather than throw) left `MAX()` mixing this run's fresh rows with a prior run's stale ones. The count gate only proves every indicator has *some* row, not that they came from one run, so a direction could be concluded from mixed-hour data that never co-existed and stamped current — driving position opening. Added a same-run provenance gate: if the spread between the oldest and newest latest-per-indicator write time exceeds `kraite.indicators.max_run_spread_seconds` (default 300s), the set is treated as inconclusive and retried next cycle. Same-run writes are seconds apart; a cross-run straggler is ~one timeframe behind, so the two never blur. 2 tests. See deploy-notes Entry 99.

### Security

- [FIXED] **Connectivity endpoints authorize against account ownership.** The three `/api/connectivity-test/*` routes required only `auth` — any authenticated user could start a check with another account's exchange credentials, fire a whitelist notification to its owner, or read per-server topology/credential state. Added `AccountPolicy::operate` (owner-or-admin), gated on all three endpoints, with the status lookup scoped through the workflow's owning account. 7 feature tests. (Zero exposure today — single-user — hardening for multi-user beta.)
- [FIXED] **ZeptoMail webhook replay/duplicate suppression.** The signed request's HMAC covers only the payload, so a captured event stayed valid forever and could clobber newer delivery state. Added an atomic `request_id` dedup (first delivery wins; also idempotent against ZeptoMail's legitimate retries) plus a 15-minute signature-timestamp age gate. 4 tests.
- [IMPROVED] **CSRF exemption narrowed from `api/webhooks/*` wildcard to the exact provider callback URIs** (ingestion `bootstrap/app.php`), so a future webhook route can't silently inherit the exemption.

## 1.64.1 - 2026-07-11

### Bug fixes

- [FIXED] **Price-alignment candidate selection excludes delisted symbols.** The TON→GRAM rebrand left Bitget's dead TON/USDT row (already flagged `is_marked_for_delisting`) sharing its `symbol_id` with Binance's renamed GRAM sibling — name divergence selected it for the live price check on every symbol refresh, and Bitget answered `Parameter TONUSDT does not exist (40034)` twice an hour (36 failed VerifyPriceAlignmentJob steps + cascading parents over two weeks). `namingDivergentCandidateIds()` now applies `notDelisted()` — the scope whose contract (post-Entry-90) names exactly this class of query. Regression test added.

## 1.64.0 - 2026-07-11

### Features

- [NEW FEATURE] **Drawdown floor — the continuation-crash fix (Jun 2022 class).** BSCS's five sub-signals measure change vs a 14-day baseline and go blind when the baseline itself is already broken (Jun 2022: score never crossed 60 while the market bled into the Celsius cliff). The hourly compute now overlays an absolute-state reading: BTC's latest 1h close >= 15% below its highest close across the last 500 bars (~21 days, matching candle retention) floors the composite score at 60 (Fragile posture: reduced count/leverage/margin). Floor only — never lowers a higher score, never reaches Critical on its own. Config `kraite.market_regime.drawdown_floor` (kill switch `MARKET_REGIME_DRAWDOWN_FLOOR`). Forensics ride the snapshot's `inputs_meta.drawdown_floor` block. Earn-a-slot study (~/blackswan/reports/signal-candidates-20260711.txt): fires 4/6 events at T-6h INCLUDING the missed Jun-2022 (36.6%), ~zero calm-month phantoms; only cost is deliberate Fragile posture during genuine >15% drawdown recoveries. 6 feature tests.

### Research decisions

- [DECISION] **Perp-basis signal candidate KILLED by data.** The Tier-A candidate from the external trader review fired in only 1/6 historical events at T-6h (needs >=4), with calm-month noise peaks exceeding every pre-crash reading — the same failure mode that eliminated funding rate in the original research. Study preserved in ~/blackswan/reports/signal-candidates-20260711.txt; not implemented.

## 1.63.0 - 2026-07-11

### Features

- [NEW FEATURE] **Live-window cascade detection — shock breaker reacts in ~1-2 minutes instead of up to ~35.** `DetectMarketShockJob` now samples the reference basket's mark prices (1s-fresh from the price daemon) into a rolling `market_price_samples` buffer every tick and evaluates the same breaker rules on true rolling windows (15/60-minute geometry over 1-minute samples). Arms only after 2 consecutive breaching ticks (persistence guard — kills single-minute wicks). Replay-validated against all six historical black-swan events (~/blackswan/reports/fast-breaker-replay-20260711.txt): earlier detection on every event (10-20 min on fast cascades during which the basket fell 9-22%; hours-to-days on the two events whose first violent leg the bar-close path missed entirely), at ~1 false 6h pause per choppy month and zero in calm months. The 15m-kline path stays as automatic fallback (daemon outage, thin buffer, `MARKET_SHOCK_LIVE_WINDOW=false` kill switch). `MarketShockCircuitBreaker::evaluate()` gained bar-offset parameters (backward-compatible defaults). New table `market_price_samples` (rolling ~3h buffer). 6 new feature tests.

## 1.62.2 - 2026-07-10

### Bug fixes

- [FIXED] **Binance order-cancel mapper guards the optional `avgPrice` field.** Binance omits `avgPrice` on cancel confirmations for never-filled orders; the unguarded read fataled the worker AFTER the cancel landed exchange-side, failing `CancelPositionOpenOrdersJob` inside the TP-fill close chain and orphaning the position's remaining DCA ladder on the exchange (position #176 SKYUSDT, 2026-07-10 night — contained by the night watch, rungs cancelled manually). Now defaults to '0' like every other optional field in the mapper. 2 regression tests. See deploy-notes Entry 98.

## 1.62.1 - 2026-07-10

### Bug fixes

- [FIXED] **Failed-backtest kline purge exempts market-reference symbols.** Rejecting BTC in admin backtesting caused `kraite:cron-purge-failed-backtested-klines` to delete BTC's entire candle history — but BTC is the alignment series every correlation/elasticity computation runs against and the BTC-bias direction source. Effect: elasticity degraded to single-timeframe zero stubs, every LONG candidate failed the per-slot completeness check, and account activation opened zero positions with 11 tradeable tokens and no visible error (discovered on go-live day, 2026-07-10). The purge now exempts the BTC reference token (`kraite.correlation.btc_token`) and the market-regime basket (`kraite.market_regime.symbols`) regardless of review status; NULL-asset rows still purge (three-valued NOT IN guard). 5 regression tests. See deploy-notes Entry 97.
- [FIXED] **CandleFactory schema drift** — factory wrote `candle_time`, schema has `candle_time_utc` / `candle_time_local`; factory-created candles crashed on insert.

## 1.62.0 - 2026-07-07

### Improvements

- [IMPROVED] **Fleet heartbeat reports the running core version** — `FleetMetricsCollector` adds a `version` field (kraitebot/core pretty version from composer metadata) so the admin deploy panel can surface rollout drift across the fleet; silent hosts / bash-agent reporters classify as null.

### Bug fixes

- [FIXED] **TAAPI 404 "no candle data" treated as a legitimate no-data answer** in `TouchTaapiDataForExchangeSymbolJob` — newly listed non-USDT quotes (BTC/U, ETH/USD1, DATAIP/USDC) hard-failed the verification probe hourly. See deploy-notes Entry 96.

## 1.61.0 - 2026-07-06

### Improvements

- [IMPROVED] **Backtest grade can no longer contradict the stop-loss decision rule.** The overall score weighs stops as a percentage of resolved sims, so a large sample diluted absolute failures — 16 stop-loss hits over ~1400 sims still graded "B — mostly fine to run" while the admin decision proposal (absolute rule: <5 approve · 5–10 adjust · >10 reject) said "recommend reject" right below it. The score is now capped by the decision band: more than 10 stops grades at best D, 5–10 at best C. Formula ordering below the cap is unchanged.

## 1.60.0 - 2026-07-06

### Improvements

- [IMPROVED] **BacktestSimulator now counts sizing-skipped sims and reports the trailing-cutoff window.** `totals.skipped` counts simulations whose market/ladder sizing was rejected (min-notional / lot-step gating on awkwardly-priced tokens) — previously these vanished into no bucket at all, so a run where EVERY sim skipped was indistinguishable from a clean zero-stop run (`stops=0` read as approvable when nothing was actually simulated). `meta.days_to_ignore` exposes the trailing-days cutoff the run used, so consumers (the admin AI-insights prompt) stop guessing it. Both additive; `emptyResult()` carries the new key too.

## 1.59.0 - 2026-07-04

### Improvements

- [IMPROVED] **Health watchdog runs even in maintenance mode.** `kraite:cron-check-system-health` is scheduled `->evenInMaintenanceMode()`; while the app is down it runs exactly one check — the new `maintenance_mode_stuck` (pages CRITICAL when the down-marker mtime exceeds `kraite.health_watchdog.maintenance_stuck_minutes`, default 45, re-pages every 30 min). Closes the blind spot where an interrupted release parked athena in maintenance for 53h with the watchdog dead alongside the scheduler it monitors (deploy-notes Entry 93). Regression: `CheckSystemHealthMaintenanceStuckTest`. *(Section added retroactively — the v1.59.0 tag shipped without a changelog entry.)*

## 1.58.2 - 2026-07-02

### Bug fixes

- [FIXED] **User-data daemon hardened against the fleet-scale reset storm — one restart no longer means N notifications, N simultaneous reconnects, or a memory crash-loop.** The daemon multiplexes one WebSocket per Binance account in a single process, so any restart resets every account at once. Three amplifiers turned that into a storm at 100 accounts, all addressed: (A) the per-account `binance_user_data_account_connected` notification fired on every (re)connect — a restart produced one message per account; connect success is now log-only and a single `binance_user_data_daemon_online` boot-summary covers the fleet, while per-account init FAILURES still page. (B) every account's handshake fired in the same event-loop tick — a thundering herd of N simultaneous connects from athena's single IP, risking Binance's per-IP WS connection rate limit; connects are now staggered on a linear ramp (`connectStaggerDelay`, 0.25s spacing → ~4/sec) with a `$spawning` guard against double-scheduling. (C) the memory self-exit ceiling was a FIXED 512MB, crossed by roughly 43 accounts of normal load (~10MB/account + base) — self-exiting and resetting the entire fleet in a crash-loop; the ceiling now scales with the live account count (`memoryLimitBytes()` = 200MB base + 25MB/account), so the self-exit fires only on a genuine leak. Regression coverage in the ingestion suite's `StreamBinanceUserDataScaleTest`.

## 1.58.1 - 2026-07-02

### Bug fixes

- [FIXED] **Strict-data WebSocket streams now self-exit for supervisor respawn after a sustained no-data window — "reconnect forever" is availability, not recovery.** On 2026-07-02 a transient network blip wedged the mark-price daemon's ReactPHP DNS resolver on its shared event loop; the daemon reconnected ~46,000 times over ~4 hours, every attempt failing, no price frames landing, until a manual restart cleared it in seconds. The existing mitigations (bypass the systemd stub for `1.1.1.1`, rebuild a fresh Pawl connector per attempt) do NOT clear a loop-level UDP wedge — only a fresh process does. `BaseWebsocketClient` now accepts an opt-in `noDataSelfExitSeconds` threshold and tracks `lastDataFrameAt` — a clock that advances only on real data frames and is never reset by a (re)connect or a keepalive ping, so it ages correctly through both failure phases (never-connects AND connects-but-silent). The idle-watchdog checks it before the connection null-guard (the DNS-fail phase holds the connection null) and, on breach, stops the event loop so supervisor's `autorestart=true` respawns a clean process — turning a multi-hour blackout into a ~10-second blip with no operator. The mark-price stream (`BinanceApi::markPrices`) opts in at 300s; the user-data stream leaves it unset because silence is legitimate on a quiet account. Regression coverage in the ingestion suite's `BaseWebsocketClientSelfExitTest`.

## 1.58.0 - 2026-06-23

### Features

- [NEW FEATURE] **Price-alignment gate — unit-divergent cross-exchange contracts are excluded from trading.** Two exchanges list the same asset under different contract units (Binance `1000FLOKI` = 1000 × KuCoin/Bybit `FLOKI`). The price stream replicates Binance's mark_price onto same-asset rows with no contract-ratio scaling, so a unit-divergent row carried a price wrong by that ratio (KuCoin FLOKI held Binance 1000FLOKI's 0.0237 vs its real 0.0000237 — ~1000× too high). New `is_price_aligned` column (boolean, default true) is required by `scopeTradeable`, so a price-divergent symbol can't trade even if re-enabled. A new refresh **index-4** step — `VerifyPriceAlignmentsJob` (parent) → `VerifyPriceAlignmentJob` (per-symbol child) — fetches each naming-divergent `symbol_id` sibling's LIVE exchange price, compares to the Binance sibling within `config('kraite.price_alignment.tolerance', 0.10)`, and on divergence sets `is_price_aligned=false` + `is_manually_enabled=false` + one deduped `exchange_symbol_price_misaligned` notification. Same-name siblings are inherently same-contract and left aligned. Additive + default-true: no trading-behavior change until a check actually proves divergence; the index-4 step doubles as the backfill (re-checks every refresh). Regression coverage in the ingestion suite's `PriceAlignmentTest`.

## 1.57.0 - 2026-06-22

### Bug fixes

- [FIXED] **Cross-exchange direction bridging keys on the canonical CMC `symbol_id`, not the ticker string.** `ExchangeSymbolObserver` (overlap detection) and `CopyDirectionToOtherExchangesJob` (the conclude-cycle copy) matched the other-exchange row by ticker string + a hand-seeded `token_mapper`, so naming-divergent symbols with no mapper row — BitGet FLOKI / SHIB and Bybit SKYAI1, the same assets as Binance 1000FLOKI / 1000SHIB / SKYAI — sat with `overlaps_with_binance=0`, null indicators, and perpetually tripped the indicator-stale watchdog. The CMC `symbol_id` already linked them correctly (and is the same identity the observer uses for backtesting / TP-SL / gap sibling propagation); the string path was the lone holdout. Both sites now resolve by `symbol_id` (naming-agnostic), keeping exact-token + `token_mapper` as the fallback for CMC-unresolved rows. Additive and low-blast: existing token matches are unchanged; only previously-orphaned divergent symbols newly bridge. A null `symbol_id` never matches (no bridge to an arbitrary Binance row) and a different `symbol_id` never matches (ticker-collision guard). Regression coverage in the ingestion suite's `SymbolIdIdentityBridgeTest` (bridges by symbol_id with no mapper; different symbol_id does not bridge; null symbol_id still bridges via the token fallback).

## 1.56.0 - 2026-06-22

### Features

- [NEW FEATURE] **Dispatcher-orchestrated candle-coverage steps for the backtest risk gate.** New `Jobs/Backtest/EnsureBacktestCandleCoverageStep` (orchestrator) audits coverage via `CandleCoverageVerifier` and, when a token's window isn't fresh + gap-free, `makeItAParent()`s a sequential child block — `FetchVisionCandlesStep` → `FetchRestCandlesStep` (forward-fill + `fillGaps`) → `FetchTaapiCandlesStep` → `VerifyCoverageResultStep` — each wrapping the existing synchronous `Support/Backtest/*` fetchers and best-effort (catch → complete) so one source hiccup never stalls the block. The final verify records `{ready, reason, coverage}` on its step `response` for the admin poll endpoint. New `Support/Backtest/CoverageGate` is the single fresh+complete predicate (latest holds the last CLOSED candle = 1× interval; `holes == 0 && contiguity >= 99%`), shared by the dispatcher verify step and the admin `run` / `approve` gates. All additive — no change to existing trading code; the steps run on the `indicators` queue. Backs the admin backtesting rule that a grade or approve can never be produced on stale or incomplete candle data.

## 1.55.1 - 2026-06-21

### Bug fixes

- [FIXED] **BitGet kline delisting self-heal now recognises code 40034.** `BitgetExceptionHandler::isSymbolDelisted()` only matched 40309 ("The contract has been removed"), but BitGet's kline / market-data endpoints report a gone contract with 40034 ("Parameter {symbol} does not exist"). So `FetchKlinesJob`'s reactive self-heal never fired for a delisted BitGet symbol — it failed every refresh cycle until the slower hourly proactive presence-sweep flagged it. Surfaced live by the 2026-06 TON→GRAM rebrand: BitGet pulled the TONUSDT contract and every kline fetch returned 40034 all day (reference/indicator data only — zero trading impact, `trading_steps` stayed clean). `isSymbolDelisted()` now also matches 40034, so the symbol self-heals on the first kline failure (marks `is_marked_for_delisting`, settles the step cleanly). Safe because 40034 (an entity-lookup miss) is distinct from 40808 ("Parameter verification exception", a malformed param such as a bad granularity) — a request-shape bug surfaces as 40808, never 40034, so this can never mass-delist the universe. Mirrors the entry-#78 leverage-job fix (1.53.3). Regression coverage in the ingestion suite's `SymbolDelistedClassifierTest` (40034 → delisted, 40808 → not).

## 1.55.0 - 2026-06-21

### Features

- [NEW FEATURE] **Fleet-silence watchdog alert lifecycle — provisioning grace + bounded re-page.** `CheckSystemHealthCommand::checkFleetMetricsSilence` no longer pages on a box that is merely mid-provision, and no longer re-pages on every check tick. A `missing` box (roster row present, no heartbeat key ever written) is skipped while its `servers.created_at` is younger than `fleet_metrics.provisioning_grace_seconds` (default 86400) — the seeded-but-not-yet-warmed window is the normal state of a box being onboarded, not an incident. A `stale` box (heartbeat key exists but aged past `stale_after_seconds`) is never graced: it reported before, so silence is always real. Once alertable, the signal re-pages once per `fleet_metrics.alert_throttle_seconds` (default 3600) instead of every 7-minute check run. An `age_seconds=null` (corrupt/unparseable `reported_at`) is now named explicitly instead of interpolating a null into the alert sentence. New config keys `fleet_metrics.alert_throttle_seconds` + `fleet_metrics.provisioning_grace_seconds`; `FleetMetricsRepository` threads `registered_at` (from `servers.created_at`) into both the live and missing-host rows as the grace anchor, and `KraiteSeeder` writes `created_at` on first registration only so a reseed never re-arms the grace window for an existing box.
- [NEW FEATURE] **`Kraite::ip()` resolves through the box's logical roster identity.** The server-IP resolver now honours `config('kraite.fleet_metrics.hostname')` before falling back to `gethostname()`. A box whose OS hostname is not its roster name (a dev Mac whose `servers` row is `local`) previously fell through every IP-scoped call (notification builds, the four exchange throttlers' `getCurrentIp`, ban diagnostics) to a live `api.ipify.org` lookup — which went silent for ~3 hours when the Mac's SSL chain broke (deploy-notes #86). With the override the resolver reads the roster row directly. Production boxes are untouched (the OS hostname IS the roster name; the env stays unset).
- [IMPROVED] **palaemon + aristaeus added to the config worker roster** — the fifth and sixth interchangeable trading workers (joined 2026-06-12).

## 1.54.1 - 2026-06-12

### Bug fixes

- [FIXED] **Fleet Redis connection is registered in `register()`, not `boot()`.** Resolving the dedicated fleet-metrics Redis connection at boot time raced the container's config resolution; binding it in `register()` makes the connection available before any boot-phase consumer reads it.

## 1.54.0 - 2026-06-12

### Features

- [NEW FEATURE] **Fleet metrics heartbeat — roster-driven, cross-platform, env-configurable.** Per-box vitals now derive their roster from the `servers` table, the collector is cross-platform (Linux fleet boxes + the macOS dev box), and the heartbeat's reporting identity / queue connection / queue name are env-configurable (`FLEET_METRICS_HOSTNAME` / `_CONNECTION` / `_QUEUE`) so the identical self-rescheduling job runs on a dev box whose roster name, default connection and Horizon queue differ from a production box's.

## 1.53.3 - 2026-06-10

### Bug fixes

- [BUG FIX] **Per-symbol leverage-bracket sync no longer lets one dead symbol poison the batch.** `SyncLeverageBracketJob` (the shared atomic used by Bybit, KuCoin and Bitget) called the exchange's per-symbol leverage endpoint with no guard: when the exchange reported a symbol closed/invalid at runtime (Bybit retCode 10001 "symbol is closed or invalid", and the per-exchange equivalents) the atomic threw, which failed the shared parent `SyncLeverageBracketsJob` ("Child step(s) failed") and recurred on every hourly refresh because nothing ever flagged the symbol. It now catches the API error, and if `isSymbolDelisted()` recognises it, marks the symbol for delisting and settles the step cleanly (mirroring `FetchKlinesJob`); any other error still bubbles up. The next refresh's lifecycle filter then excludes the symbol entirely, so it self-heals. Sibling symbols already completed independently — this removes the parent-poisoning + recurring failure and closes the "listed-but-leverage-closed" blind spot the proactive orphan-sweep and the kline detector both miss (2026-06-09: AAOI/USDT on Bybit). Regression coverage in the ingestion project's `SyncLeverageBracketJobDelistingTest`.

## 1.53.2 - 2026-06-09

### Bug fixes

- [FIXED] **`group_no_progress_detected` alert now points the operator at the table set that actually stalled.** The watchdog runs once per dispatcher prefix (`steps:recover-stale` for the default set, `--prefix=trading` for the trading set), but the embedded resolution SQL in the alert hardcoded `FROM steps` / `FROM steps_dispatcher` — so a `trading_steps` group stall (the 2026-06-09 gamma case) printed default-set diagnostics and sent the responder to the wrong table. `SendStaleStepsNotification` already reads the active `RuntimeContext` prefix for pause-suppression; it now also resolves the real table names from it and passes `steps_table` / `dispatcher_table` in `referenceData`. `NotificationMessageBuilder` renders those in all four resolution queries (falling back to `steps` / `steps_dispatcher` when absent, so other callers are unaffected) and adds a "Table set:" line to the alert body. Covered by two cases in the ingestion project's `SendStaleStepsGroupProgressNotificationTest` (trading prefix → `trading_steps` SQL; default → `steps` SQL).

## 1.53.1 - 2026-06-09

### Bug fixes

- [FIXED] **`RecoverPositionsCommand::hasInflightStepFor` no longer counts parked `NotRunnable` rescue steps as in-flight work.** The in-flight guard excluded only terminal states, so the `NotRunnable` `CancelPositionJob` that every successfully-opened position leaves behind (a resolve-exception "rescue" branch that runs only if a sibling fails) looked like live work. Recovery therefore deferred forever on any healthy position — the exact case that blocked `kraite:recover-positions` from rescuing the 8 positions wedged in `syncing` on 2026-06-09. `NotRunnable` is now merged into the excluded set alongside the terminal states; a genuinely failing block still trips the guard through its real non-terminal sibling (Pending / Dispatched / Running). Covered by `tests/Feature/Commands/RecoverPositionsNotRunnableInflightTest.php` in the ingestion project.
- [FIXED] **`SendStaleStepsNotification` reads the notification row's `cache_duration` instead of hardcoding a 600s throttle.** The listener passed `duration: 600` literally, which would starve a Notification Threshold armed on `group_no_progress_detected` (the threshold counts only post-throttle occurrences, so the throttle must be droppable to 0). It now defers to the row's `cache_duration`, which defaults to 600 — so behaviour is unchanged until a threshold is switched on for the canonical.

## 1.53.0 - 2026-06-08

### Features

- [NEW FEATURE] **Notification Threshold — opt-in escalation gate on top of the Throttler.** A notification flagged `has_threshold` is only physically delivered once it has recurred `threshold_max_notifications` times within a rolling `threshold_max_duration_minutes` window. Sub-threshold occurrences are still recorded in `notification_logs` (`passed_threshold = false`, status `threshold held`) but not sent — turning benign-when-rare / serious-when-frequent alerts (e.g. Bybit retCode 10006) into a single alert only when they actually cluster. The throttler still runs first and only its survivors are counted; counting is per `(notification_id, relatable)` over the "held" rows, and re-earns after each breach via an id-high-water-mark cache anchor (immune to sub-second bursts, consistent across workers). Inert by default (`has_threshold` defaults false → every existing notification unchanged); thresholds are switched on per notification, by hand. New columns: `notifications.has_threshold/threshold_max_notifications/threshold_max_duration_minutes`, `notification_logs.passed_threshold` (default true). The DB-throttle window now counts only delivered rows so a notification's own held rows can't starve its threshold. Fully fail-open: a threshold write error or unkeyable relatable never breaks the caller.

- [NEW FEATURE] **Phase 3 — regime-scaled leverage + position-count ramps; Critical is absolute.** Each BSCS regime band now drives three stacking, open-time-only risk axes: leverage scales down (`floor(base × ratio)`, min 1×), position count scales down (`floor(account_max × ratio)`, gate-only — an over-cap book freezes new opens and never force-closes), and the existing margin slice. Every opened position records the band+direction it was born under (`positions.bscs_band`, e.g. `elevated-long`) and the raw `positions.bscs_score`. The per-account `respect_bscs` opt-out and the operator `bscs_override_until` escape hatch are both removed — a Critical-armed cooldown blocks new opens for every account and cannot be bypassed; the only fail-open path left is staleness.

## 1.52.0 - 2026-06-07

### Features

- Per-account `respect_bscs` flag — accounts may open positions without waiting on the BlackSwan (BSCS) cooldown gate. Defaults true (honour BSCS).
- Runtime-configurable settings on the `kraite` singleton: `can_trade` master kill-switch, `notifications_enabled`, `td_correlation_type`, correlation/elasticity computation toggles, and trail-retention hours — all read with safe normally-open fallbacks.
- Per-account token-discovery gates: `use_correlation_sign_filter`, `use_btc_bias_restriction`.
- Global→account notification cascade — a global gate silences all sends when off; when on, each user's `notifications_enabled` decides, except Critical alerts which always reach the account user.
- Dispatcher-group drain-recheck follow-up: when a group stalls, a non-critical `VerifyDispatcherGroupDrainedJob` is injected +15min out to report whether the group drained (Info) or is still stalled (High).
- athena added as a second `indicators` consumer (10 procs) — a second public IP on the kline/indicator lane so StepRouter spreads the per-IP Bybit kline burst (retCode 10006) across two IPs and can rotate off a rate-limited IP.

### Bug fixes

- Daily/fleet PnL aggregates now sum the exchange-reported `positions.pnl` (realized net of fees + funding) instead of the inflated `profit_percentage / 100 × margin` (margin was the per-slot wallet allocation, not notional — ~4× high).

### Config

- Removed the dead `CAN_TRADE` / `CAN_OPEN_POSITIONS` env keys (superseded by the `kraite` singleton master kill-switch).

## 1.51.9 - 2026-06-06

### Bug fixes

- [FIXED] **Position-mode auto-flip no longer oscillates under concurrent -4061s.** `HandlesApiJobExceptions::autoFlipPositionMode()` checked its 10-minute anti-oscillation cooldown OUTSIDE the `lockForUpdate` transaction. When a LONG and a SHORT order open on two workers and both hit `-4061` in the same second, both passed the cooldown check before either stamped it, then blind-inverted `on_hedge_mode` 0→1→0 — netting back to the wrong mode and locking out further flips for 10 minutes. The cooldown is now re-checked INSIDE the lock, so the second serialised job sees the first's stamp and no-ops. First-live-go-live incident (2026-06-06): BAS long + 1000FLOKI short. Regression test added. deploy-notes entry 73.

## 1.51.8 - 2026-06-06

### Bug fixes

- [FIXED] **Per-IP notification subjects are now unique — fixes mail-provider dedup.** The four per-IP canonicals (`server_ip_not_whitelisted`, `server_ip_rate_limited`, `server_ip_banned`, `server_ip_forbidden`) all carried an identical hardcoded title; the IP lived only in the body. During an IP-rotation event the engine correctly bans each of the four trading workers and fires one alert per IP, but with identical subjects + same sender within seconds, mail providers (Gmail) collapse the duplicates — the operator saw 2 of 4. The title now embeds the IP (and hostname), so every alert has a distinct subject and all arrive. deploy-notes entry 72.

## 1.51.7 - 2026-06-06

### Bug fixes

- [FIXED] **`position_opening_failed` notification throttle keyed per-account (was per-position).** The KraiteSeeder definition + `HasStatuses::updateToFailed` dispatch used `cache_key=['position']` / 60s — but every failure carries a fresh position id, so the throttle never actually deduped: a sustained opening outage emailed the operator on every 3-minute create-positions tick. Now `cache_key=['account']` / 3600s → one alert per account per hour. Surfaced 2026-06-06 (go-live: a futures-permission-blocked API key produced 3 emails across positions 1-4 in 6 minutes).

## 1.51.6 - 2026-06-06

### Features

- [NEW FEATURE] **`ProfitableTokensBacktestApprovalSeeder`.** Backfills `was_backtesting_approved = true` for the 141 tokens that closed at least one position in profit on the operator's live Binance account (12-month REALIZED_PNL extraction, 1,242 closed positions, generated 2026-06-06). Flips the Binance/USDT rows via Eloquent so `ExchangeSymbolObserver` propagates approval cross-exchange — equivalent to batched operator approval. Idempotent; missing/delisted tokens are reported and skipped. Replaces the approvals lost in the 2026-05-31 `migrate:fresh` ahead of production go-live.

## 1.51.5 - 2026-06-06

### Features

- [NEW FEATURE] **Configurable position-trail retention (`kraite.positions.trail_retention_hours`, env `KRAITE_TRAIL_RETENTION_HOURS`, default 0).** At 0 the breadcrumb janitor (`PurgePositionTrailJob`) keeps firing the instant a position closes — unchanged behavior. At N > 0 `PositionObserver` skips the immediate purge and the new `kraite:cron-purge-position-trails` sweeper command (`--hours-to-keep` override, `--dry-run`) dispatches the janitor only for closed positions whose `closed_at` aged past the window AND that still carry trail rows (idempotent, trail-existence-driven selection). Purpose: let the periodic DB backups capture the diagnostic trail before reclamation — production intent is 24h. `cancelled` / `failed` exits keep their forensic trail forever, mirroring the existing janitor contract.

## 1.51.4 - 2026-06-05

### Bug fixes

- [FIXED] **Dispatch daemon idle gate reads DB truth per prefix instead of the default-prefix flag file.** `kraite:dispatch-daemon`'s loop gated on `StepDispatcher::isActive()` outside any prefix ambient — it only ever saw the default `active.flag`. When the default prefix drained (cron steps finish in seconds) the daemon slept with trading steps still Pending; trading work only moved in the seconds after a scheduler cron recreated the flag, turning position open/close ladders into a one-hop-per-minute crawl (~6 min per open). The flags are also per-machine, so worker-created child steps could never wake athena's daemon. The gate now checks `StepDispatcher::hasActiveSteps()` per prefix (sub-ms EXISTS against the shared DB — prefix-correct and fleet-correct) and ticks each prefix independently. Verified live: index hops dropped from 27-60s to 1-4s. Pairs with brunocfalcao/step-dispatcher 1.13.3 (the StepObserver priority-queue clobber). Deploy-notes entries 69-70.

## 1.51.3 - 2026-06-05

### Bug fixes

- [FIXED] **`horizon.workers.pheme` logical queue renamed `pheme-web` → `web`.** The `{hostname}-{logical}` physical-queue composer only skips the prefix when the logical name equals the hostname exactly, so logical `pheme-web` composed to physical `pheme-pheme-web` — while every doc, operator runbook, and Redis inspection referred to `pheme-web`. Logical `web` now composes to physical `pheme-web`, matching the documented name. Until pheme's per-app Horizons restart on this version they keep subscribing to the old `pheme-pheme-web` physical (all web queues are empty, so nothing strands). Pairs with ingestion 1.53.3, which carries the same rename in the project-level override.

## 1.51.2 - 2026-06-01

### Improvements

- [IMPROVED] **`kraite.horizon` block added to package config (full workers map).** Mirrors the structure that previously lived only in the ingestion project's `config/kraite.php`. Without this, web apps (admin / console / kraite.com) that don't ship their own project-level `config/kraite.php` would boot with no `kraite.horizon.workers` available — the `CoreServiceProvider::syncHorizonEnvironmentsFromKraiteConfig()` transformer would silently produce no environments for `HORIZON_ENV=pheme` and the pheme Horizon supervisor would consume nothing. The ingestion project keeps its own override for the same block (small duplication, asserted aligned by `kraite:verify-fleet-topology --fail-on-drift`).
- [IMPROVED] **`horizon.workers.pheme` block included.** Two-queue subscription: `pheme-web` (2 procs — web-originated jobs) + `pheme` (1 proc — per-hostname connectivity probe slot, kept for symmetry even though pheme makes no exchange API calls). The StepRouter candidate map is built off logical-queue keys, so listing only `pheme-web` here guarantees pheme is never picked for `positions / orders / priority / cronjobs / indicators / user-data-stream`. The architecture is self-enforcing: pheme stays out of the trading dispatch path by virtue of what's missing from its workers block, not by a separate guard.

## 1.51.1 - 2026-06-01

### Improvements

- [IMPROVED] **`kraite.fleet.servers.pheme` added.** New `web` role entry pointing at `62.238.38.113`, `is_apiable: false`. The dedicated web host took the `admin / console / kraite.com / syntax` vhosts off athena on 2026-06-01 — fleet.servers now reflects that split so `kraite:verify-fleet-topology` doesn't trip on a missing-config gap once the deferred `horizon.workers.pheme` block lands.
- [IMPROVED] **Athena `fleet.servers` entry updated.** Description trimmed to ingestion-only (scheduler + dispatch-daemon + WS daemons + user-data Horizon). Web role line removed.

## 1.48.0 - 2026-05-25

### Features

- [NEW FEATURE] **`kraite:verify-fleet-topology` artisan command.** Asserts every key in `config('kraite.horizon.workers')` has a matching `servers.hostname` row. Without that alignment, StepRouter cannot map a banned IP back to the hostname that belongs to a config key — ban filtering silently fails for the drifted worker and the rotation engine becomes a no-op. Detects two drift directions: config keys with no servers row (the load-bearing case) and apiable server rows with no config block (operational hygiene — orphan workers that will never receive any dispatched steps). Flags: `--fail-on-drift` (exit code 1 on drift; used by `deploy.sh` after `config:cache` and before `horizon:terminate` so a broken deploy aborts before workers respawn against the stale state), `--quiet-on-success` (suppresses the OK line for cron / boot invocations).
- [NEW FEATURE] **`Kraite\Core\Support\StepRouter`** — dispatch-time queue resolver registered with step-dispatcher v1.13.0's `setQueueResolver()` hook. Replaces the pickup-time rotation engine from v1.47.0. At dispatch time the router:
  - Strips any known-hostname suffix from the step's `queue` field to recover the logical category (e.g. `positions-eos` → `positions` on a retry)
  - Reads the candidate worker list for that logical queue from `config('kraite.queue_subscriptions')`
  - Extracts the account context from `step.arguments.accountId` (or `step.relatable_id` when the step relates to an Account)
  - Loads active `forbidden_hostnames` for the (account, api_system) pair (both account-scoped and system-wide rows)
  - If an `account_blocked` ban exists → fires the deactivation cascade and throws `StepDispatcher\Exceptions\NoCleanWorkerException`
  - Otherwise filters out workers whose IP appears in rotation-eligible bans (`ip_not_whitelisted`, `ip_rate_limited`, `ip_banned`)
  - Picks a random clean worker → returns the physical per-hostname queue (e.g. `positions-eos`)
  - If no clean worker exists AND a permanent ban (`ip_not_whitelisted`) is in the set → fires the deactivation cascade and throws
  - If no clean worker exists but only temporary bans are present → returns `null` (step-dispatcher leaves `step.queue` as-is, existing retry mechanics take over until the temporary ban expires)
  Registered in `CoreServiceProvider::boot()` after the Queue listeners. Sync steps (`queue=sync`) never reach the router — step-dispatcher bypasses the hook for them. Orchestrator (no-account) steps skip the ban filter and route to any eligible worker for the logical queue.

### Behaviour changes

- [IMPROVED] **`BaseApiableJob::compute()` simplified to the execute path only.** Pre-flight ban detection + rotation logic shipped in v1.47.0 has been removed — that responsibility moved to dispatch time inside `StepRouter` per the v1.13.0 design. `compute()` now just asserts the exception handler exists, assigns it, and calls `computeApiable()` wrapped in the API-specific exception chain. The `deactivateAccountAndFail()` private method was removed in lockstep (its caller is gone); the equivalent cascade lives in `StepRouter::deactivateAccountAndNotify()`.

### Removed

- [REMOVED] **The v1.47.0 rotation engine in `BaseApiableJob`** has been deleted in this release. The same outcomes (rotate banned IP, deactivate on exhaustion, loud notification) are achieved by `StepRouter` at dispatch time. Pickup-time rotation conflicted with the priority promotion path (`RecoverStaleStepsCommand` overrides the queue column on stuck Dispatched steps, breaking IP isolation); dispatch-time routing has no such conflict because the routing decision is made before the step ever enters the Redis queue.

## 1.47.0 - 2026-05-25

### Features

- [NEW FEATURE] **Worker-IP rotation engine in `BaseApiableJob::compute()`**. When an API job runs on a worker whose public IP is blacklisted on the exchange for the target (account, api_system), the step is now re-routed onto a clean worker's per-hostname queue via `Step::rotateToQueue()` (step-dispatcher ≥ v1.12.3) — rather than retrying locally against an IP that cannot succeed. The "lives" of a step shift from retry-count to IPs-exhausted: each fleet IP is one attempt, and the step only fails when every apiable worker is banned. Source of truth is the `servers` table (`is_apiable=1, needs_whitelisting=1, own_queue_name=…`). Picks a random clean worker each rotation cycle.
- [NEW FEATURE] **Terminal deactivation cascade.** When `account_blocked` is detected (bad API key — no IP rotation can save it) or every worker IP is blacklisted for an account, `BaseApiableJob` flips `accounts.is_active=0` with `disabled_reason="All worker IPs blacklisted on {Exchange} — fix whitelist/API key and reactivate"` so downstream cronjobs / dispatchers stop fanning steps to the account. The current step transitions to `Failed` instead of retrying. Temporary bans (rate-limited / IP-banned with a future expiry) still retry naturally because they auto-recover on window expiry; only permanent bans (`ip_not_whitelisted` + `account_blocked`) trigger the deactivation path.
- [NEW FEATURE] **`account_all_workers_blacklisted` notification canonical** (Critical severity, throttled 1h per account-api_system pair). Fires alongside the deactivation cascade. Email + Pushover copy emphasises "YOUR PORTFOLIO IS UNMANAGED" and walks the user through the recovery checklist (verify API key validity + permissions, add every worker IP to the whitelist, re-activate the account in the admin panel). Distinct from the per-ban `ForbiddenHostnameObserver` notifications — that one says "this IP is blacklisted", this one says "we've given up on this account because every IP is".

### Behaviour changes

- [IMPROVED] **`forbidIpNotWhitelisted` and `forbidAccountBlocked` now stamp a 1-hour `forbidden_until` on the `forbidden_hostnames` row** instead of `null` ("sticky until proof of repair"). The rotation engine treats these rows as the source-of-truth blacklist and needs a natural re-probe cadence — without expiry, a single mis-whitelisted IP locks out forever until manual operator intervention. When the user fixes the underlying issue, the next successful authenticated call inside the hour self-heals the record via the existing success-path cleanup; if unresolved, the next failed call simply refreshes the TTL via `updateOrCreate` (no duplicate user notification).

## 1.46.3 - 2026-05-17

### Fixes

- [BUG FIX] `KraiteSeeder` now stamps seeded admin users with `status=active` and an explicit UUID when model events are disabled.
- [BUG FIX] Testing seeds no longer call the public IP resolver; local API server rows use `127.0.0.1` under `APP_ENV=testing`.
- [BUG FIX] `migrateKraiteCredentials()` now persists a configured Resend API key without clearing an existing stored key when config is empty.

### Improvements

- [IMPROVED] Shared environment loading now syncs Resend and ZeptoMail service config values after `.env.kraite` is loaded.
- [IMPROVED] Email verification messages now include the raw verification URL as fallback copy.
- [IMPROVED] Added `kraite.website_url`, derived from `APP_URL` with `admin.*` hosts mapped to the public website domain, for legal and marketing links rendered outside the marketing app.

### Removed (dead-code sweep)

- [REMOVED] **`DashboardApiController` (818-line stub)** plus its four routes (`GET /api/dashboard/{data,stats,positions,positions/{id}}`). The controller returned hardcoded fake data and had no consumer frontend calling it.
- [REMOVED] **`ConnectivityTestController::start()` tombstone** and its `POST /api/connectivity-test/start` route. The method returned HTTP 410 Gone unconditionally; the account-based connectivity flow (`startAccount`/`status`/`notifyAccountServer`) replaces it.
- [REMOVED] **Two unread config keys** in `config/kraite.php`: `kraite.health_check_secret` (defined via `env('HEALTH_CHECK_SECRET')` but never read by any consumer) and `kraite.indicators.jobs_per_index_batch` (defined via env with no readers).
- [REMOVED] **Three unused imports** scrubbed: `ApiSystemObserver` (`use Kraite\Core\Models\ApiSystem`), `IndicatorObserver` (`use Kraite\Core\Models\Indicator`), and `ReplacePositionOrdersJob` (`use Kraite\Core\Support\Proxies\JobProxy`).

## 1.46.2 - 2026-05-16

### Fixes

- [BUG FIX] **Orphan position recovery in `CreatePositionsCommand` now runs BEFORE the `isReadyToTrade()` subscription gate.** Pre-fix, an account whose subscription had lapsed (or had not yet activated) would skip the entire per-account block, including the self-heal path for orphan `status='new'` positions whose `DispatchPositionJob` step had been swept. Result: a lapsed subscription stranded existing positions in `new` indefinitely, contradicting the code's own inline contract ("Runs unconditionally — recovering a stranded orphan doesn't compete for slot capacity, the slot is already taken"). Recovery is now invoked first; only the new-opens path remains gated by readiness. Locked by the existing `CreatePositionsCommandOrphanRecoveryTest` Pest spec (4/4 green).



### Fixes

- [BUG FIX] **`AttachPrivateBetaCoupon` is no longer `ShouldQueue`.** The previous release queued the listener under the assumption it needed cross-process Redis routing to reach the ingestion worker. With the listener already lifted into `kraitebot/core` (every app autoloads it identically), there is no cross-process problem to route around — the queue dance was just adding a Redis-DB-must-match coupling between kraite.com and ingestion that was silently swallowing dispatches when the two apps happened to point at different Redis databases. Listener now runs synchronously in whichever process fires the event; the work is a single indexed lookup + (if absent) one insert, cheap enough to keep on the request path. Coupon is attached before the verify response returns.

## 1.46.0 - 2026-05-15

Lifts the Coupon entity + the private-beta auto-attach listener into the shared package so the marketing site (kraite.com) — which dispatches `UserEmailConfirmed` after a successful verify click — can actually route the event to a registered listener. Before this release the listener lived in ingestion's `App\Listeners` namespace and was invisible to kraite.com's autoloader, so dispatching the event from `PrivateBetaController@verify` was a silent no-op (coupon never attached). Pairs with ingestion v1.48.0 (drops the duplicate ingestion-local files + updates test imports) and kraite.test v0.12.0 (bumps the path-package ref to pick up the wiring).

### Features

- [NEW FEATURE] **`Kraite\Core\Models\Coupon`** + **`Kraite\Core\Models\CouponUser`** — moved from `ingestion App\Models`. Public surface unchanged: `scopeGloballyActive`, `isGloballyActive`, `bonusFor`, `privateBeta()`, `users()`, the pivot's `isActive` + `coupon()` relation, etc.
- [NEW FEATURE] **`Kraite\Core\Listeners\AttachPrivateBetaCoupon`** — moved from `ingestion App\Listeners`. Now implements `ShouldQueue` with `$queue = 'cronjobs'` so dispatch from any process (kraite.com, console, admin, ingestion itself) serializes onto the shared Redis queue → ingestion Horizon worker picks it up. Idempotency / lockForUpdate semantics unchanged.
- [NEW FEATURE] **`CoreServiceProvider::boot()`** wires the listener via `Event::listen(UserEmailConfirmed::class, [AttachPrivateBetaCoupon::class, 'handle'])`. Loaded by every app that includes `kraitebot/core`, so the marketing site, admin, and ingestion all route the event identically.

## 1.45.0 - 2026-05-15

Event-on-activation: dispatches `PreparePositionsOpeningJob` the moment an account's `can_trade` flips to true, instead of waiting up to 3 minutes for the next `kraite:cron-create-positions` tick. Cuts the wall-clock time from "user completes registration" to "first trade attempt" by ~3 min on the happy path. Pairs with ingestion v1.47.0 (4-test Pest spec locking the contract).

### Features

- [NEW FEATURE] **`AccountObserver::updated`** now reacts to `can_trade` transitions. Fires `PreparePositionsOpeningJob` under `Steps::usingPrefix('trading')` when (a) `can_trade` was actually changed, (b) it's now true, and (c) `Account::isReadyToTrade()` confirms all 3 gates (account + user + subscription) pass. Deduped against any already-pending step for the same account so a concurrent cron tick can't double-dispatch. Failure to dispatch is logged + swallowed — the original `Account::update` always succeeds; the cron picks up the slack on the next tick.

## 1.44.0 - 2026-05-15

Billing facade Bruno asked for during the private-beta onboarding elicitation: `$user->billing()->subscription()->isActive()` + the matching `Account::isReadyToTrade()` 3-gate. Pairs with ingestion v1.46.0 (consumer wired into `CreatePositionsCommand`) — locked by an 11-test Pest spec in ingestion.

### Features

- [NEW FEATURE] **`Kraite\Core\Support\Billing\BillingManager`** — top-level facade returned by `User::billing()`. Hands out scoped sub-facades. v1 only exposes `subscription()`; Phase 2 will grow `wallet()` (topUp / rollback) and `coupons()` off the same surface.
- [NEW FEATURE] **`Kraite\Core\Support\Billing\SubscriptionState`** — read-only facade over the existing User subscription helpers. Exposes `isActive()` (inverse of `isInClosingMode()`), `isPaused()`, `isInTrial()`, `trialIsExpired()`, `coversNextRenewal()`, `shortfallUsdt()`. Polarity rule: `isActive() === ! $user->isInClosingMode()`.
- [NEW FEATURE] **`User::billing(): BillingManager`** — single entry point.
- [NEW FEATURE] **`Account::isReadyToTrade(): bool`** — the 3-gate Bruno locked: `account.is_active && account.can_trade` (gate 1) AND `user.can_trade` (gate 2) AND `user->billing()->subscription()->isActive()` (gate 3). Returns true iff all three pass.

### Improvements

- [IMPROVED] **`CreatePositionsCommand`** now skips per-account work the moment `! $account->isReadyToTrade()` instead of the previous account-level / user-level scattered DB filter. Existing query still pre-filters gates 1+2 at the DB for index-friendly cost; the in-loop call adds gate 3 (subscription state) which can't easily be expressed as a single SQL where clause. Eager-loads `user.subscription` so the subscription check stays free of N+1.

## 1.43.0 - 2026-05-15

Adds a public-facing `users.uuid` column so URLs that leak nothing about row count or creation order (e.g. `admin.kraite.com/register/{uuid}`) can address a user safely. Pairs with ingestion v1.44.0 (migration that adds + backfills the column) and kraite.test v0.10.0 (verify-link redirect to admin registration page).

### Improvements

- [IMPROVED] **`User::booted()` auto-stamps `uuid`** with `Str::uuid()` at `creating` time if the caller didn't set one. Idempotent on existing rows (only fires when empty). New `@property string $uuid` PHPDoc.

## 1.42.0 - 2026-05-15

Shared `UserEmailConfirmed` event class. Pairs with `kraite.test` v0.9.0 (firing site) and `ingestion.kraite.test` v1.43.0 (`AttachPrivateBetaCoupon` listener).

### Features

- [NEW FEATURE] **`Kraite\Core\Events\UserEmailConfirmed`** — fired the moment a user's `email_verified_at` transitions from null to a timestamp. Lives in the shared package so the marketing site (kraite.com) and the ingestion worker (which listens for it) can both reference the same class across the Redis-backed queue. Carries the user id only; listeners refetch the User model so they see the latest DB state.

## 1.41.0 - 2026-05-15

Rename onboarding canonicals from `waitlist_*` to `private_beta_*`. Marketing surface now reads "private beta" everywhere; the canonical names follow.

### Improvements

- [IMPROVED] **`NotificationMessageBuilder` match arms renamed.** `waitlist_email_verification` → `private_beta_email_verification`, `waitlist_welcome_password_reset` → `private_beta_welcome_password_reset`. Email bodies and Pushover summaries reworded ("Kraite waitlist" → "Kraite private beta", "approved off the waitlist" → "approved into the Kraite private beta").
- [IMPROVED] **`test:notification` command** signature + onboarding canonical list + match arms + private method names + test verification URL path (`/private-beta/verify/...`) follow the rename. Existing TestNotificationCommand callers must update to the new canonical names.
- [IMPROVED] **Docstrings/comments in `AlertNotification` + `config/kraite.php`** refreshed to reference the new private-beta canonicals.

### Breaking — for consumers

- Any caller passing `canonical: 'waitlist_email_verification'` or `canonical: 'waitlist_welcome_password_reset'` to `NotificationService::send` MUST switch to the `private_beta_*` names. `NotificationMessageBuilder` now throws on the old names (unknown-canonical default arm). Coordinated changes land in `ingestion.kraite.test` (notifications DB rows) and `kraite.test` (controller + URLs + copy).

## 1.40.0 - 2026-05-13

Code-review pass closing reviews 10–24. ~50 source files patched; 2 new migrations; 11 new TDD test files. Foundational refactors (state machines, durable audit tables, atomic Redis reservations, normalised DTOs, replay queues) consistently discarded — narrow safety patches only.

### Features

- [NEW FEATURE] **`orders.recreated_from_order_id` lineage column.** Nullable indexed FK so `RecreateCancelledOrderJob` can resume a prior replacement on retry instead of placing a duplicate exchange order. Same idempotency parity as `PlaceMarketOrderJob` / `PlaceLimitOrderJob`.
- [NEW FEATURE] **`UserDataStreamEvent::$closePosition`.** Mapper populates from Binance `o.cp` on ALGO_UPDATE frames. Manual-close detection now accepts either `reduceOnly === true` OR `closePosition === true` for unowned filled close events.
- [NEW FEATURE] **`UpdatePositionStatusJob::withOnlyFromStatus(array)` lifecycle wrapper** + close + cancel orchestrators declare legal predecessors for every transition.
- [NEW FEATURE] **`MaintenanceMode::resumeAllStepsDispatch()`** + `activeDispatcherPauses()` helpers. `kraite:warmup` now resumes all known prefixes.
- [NEW FEATURE] **`--allow-untested-exchange` flag** on `kraite:recover-positions`. Bybit + KuCoin recoverers flagged `isUntested(): true` and are gated off by default.
- [NEW FEATURE] **`account_drift_snapshot_failed`, `position_exchange_only_detected`, `orders_exchange_only_detected`** notifications surface drift outcomes the previous alert path missed.

### Improvements

- [IMPROVED] **All 4 `OrderObserver` dispatch sites re-read the locked Position row** + status-guard against `activeStatuses()` before any state write or `Step::create`. Closes the cross-workflow race the original lock-only pattern was blind to.
- [IMPROVED] **Throttler fail-CLOSED on Redis outage** across all 4 throttlers (Binance / Bybit / Bitget / KuCoin). Pre-fix, cache failure returned `false` (allow traffic) — exactly when distributed coordination was unavailable.
- [IMPROVED] **`forbidIpNotWhitelisted` + `forbidAccountBlocked` records are sticky** (no `forbiddenUntil`). User-action-required failures no longer auto-retry daily; existing success-path self-heal cleans the record on the next valid call after credentials repair.
- [IMPROVED] **`autoFlipPositionMode` 10-minute Cache cooldown** stops the bounce-on-every-retry pattern.
- [IMPROVED] **`BaseApiClient` explicit Guzzle `timeout=30s` + `connect_timeout=10s`** with per-class override props. Removes the unbounded-socket worst-case where a stalled exchange connection could pin a worker indefinitely.
- [IMPROVED] **`Order::apiModify` signature widened to `string|int|float|null`.** Decimal precision flows verbatim to mapper-layer `(string)` boundary instead of round-tripping through PHP's 14-digit float-to-string default.
- [IMPROVED] **Recovery dual-prefix freeze + cancel** (default + trading). Phase 4 in-flight-step guard. Multi-exchange position-key extraction (`positionAmt | size | contracts | total | currentQty`). Position-scoped order idempotency. `MYSQL_PWD` env var instead of `-p<pass>`. Snapshot failure refuses `--override` unless `--allow-snapshot-failure`.
- [IMPROVED] **`Position::limitOrders()` + `profitOrder()` exclude `REJECTED`** alongside `CANCELLED` / `EXPIRED`. Rejected TP no longer counts as "present" in the structure audit.
- [IMPROVED] **`DriftCheckService::normalizeDirection`** recognises Bitget one-way `side='long' / 'short'` (was misclassified as SHORT) + multi-exchange quantity precedence.
- [IMPROVED] **`MapsAlgoOrdersQuery`** accepts both bare-array and `{"orders": […]}` envelope shapes.
- [IMPROVED] **`BaseApiClient::processRequest`** runs `shouldThrowExceptionFromHTTP200` BEFORE `recordSuccessfulResponse`. Bybit/BitGet API-level errors in HTTP 200 bodies now flow through the failure log path, not the success path.
- [IMPROVED] **`BitgetExceptionHandler::isIpRateLimited`** requires explicit `Retry-After` header before classifying a 429 as IP-level. Soft 429s now flow through `rescheduleWithoutRetry()` instead of creating fleet-wide `ForbiddenHostname` records.
- [IMPROVED] **`Account::scopeEligibleForBinanceUserDataStream` + `isEligibleForBinanceUserDataStream`** require both `binance_api_key` AND `binance_api_secret`.
- [IMPROVED] **`StreamBinanceUserDataCommand` close + error WebSocket callbacks** call `closeAndUnsetSlot($account->id)` so the next discovery tick can re-spawn.
- [IMPROVED] **`DispatchPositionSlotsJob` re-fetches account + user state at the final dispatch boundary** and refuses to dispatch slots if disabled mid-workflow.
- [IMPROVED] **`kraite:recover-positions --override` w/o scope requires `--force`**; `kraite:cron-store-accounts-balances --clean` outside `local` requires `--force`; `kraite:safe-to-restart` and `kraite:cooldown` inspect both default and `trading` prefixes.
- [IMPROVED] **Cooldown probes return `-1` (UNKNOWN) on Redis/DB failure** with explicit fail-closed reasons in status output.
- [IMPROVED] **User-data dead-letter writes check `file_put_contents()` return value.** Lossy writes emit a critical log line tagged `DEAD-LETTER WRITE FAILED — frame LOST` instead of the generic "stashed to disk".
- [IMPROVED] **`ProcessUserDataEventJob::resolveLocalOrder`** scopes `exchange_order_id` / `client_order_id` lookups by account + api_system. Closes the cross-account leak vector.
- [IMPROVED] **`AbstractPositionRecoverer::upsertOrder`** scopes the idempotency check by `position_id`.
- [IMPROVED] **Recovery `restoreTrading` no-clobber-newer-halt.** A safety halt set during recovery (e.g. structure_broken) survives the restore.
- [IMPROVED] **`getEffectiveMinNotional`** returns `?string` (was `?float`) — aligns with engine's string-decimal `Math::*` discipline.
- [IMPROVED] **`DispatchLimitOrdersJob`** surfaces `__meta.warnings` (price clamps, dropped rungs) via `position->appLog('ladder_warnings_observed', …)` BEFORE any Step::create runs.

### Hardening

- [HARDENED] **`Kraite::computeMarketOrder` + `HasPositionPlanning` trait deleted.** Both had zero callers across core + ingestion + admin. The "false-canonical" trap they created (looked canonical, took undivided margin) is gone.
- [HARDENED] **Dead `dispatchSyncOrders` + `dispatchCancelOrphanLifecycle` deleted from `CheckDriftsCommand`.** Command renamed alert-only in description + docblocks. `HEAL_PAIR_STATUSES` → `ALERT_PAIR_STATUSES`.
- [HARDENED] **`api_data_stream.idempotency_key` column comment aligned** with the actual md5-of-full-payload implementation.
- [HARDENED] **Connectivity-test API now requires auth** middleware (was throttle-only public).

### Migrations

- `2026_05_13_080000_add_recreated_from_order_id_to_orders` — nullable indexed column on `orders`. Safe online ALTER.
- `2026_05_13_090000_align_api_data_stream_idempotency_key_comment` — column-comment-only ALTER. Metadata operation, no data lock.

## 1.35.0 - 2026-05-09

### Features
- [NEW FEATURE] Migration to drop `waitlist_subscribers` table — waitlist now uses the `users` table directly
- [NEW FEATURE] Migration to update admin user credentials (email → `bruno@kraite.com`)

### Improvements
- [IMPROVED] `composer.json` dependencies pinned to semver with `dev-master` branch-alias support

## 1.34.8 - 2026-05-09

### Fixes

- [BUG FIX] **`Math::multiply()` typo in `VerifyPositionResidualAmountJob`.** Method does not exist on `Math` (it is `Math::mul`). Latent because the branch only runs when `Math::lt($positionAmt, '0')` is true — i.e. one-way mode SHORT residuals; never fired in production logs. Replaced with `Math::sub('0', $positionAmt)` for clearer sign-negation intent.
- [BUG FIX] **Drift detector strict `!==` replaced with `Math::equal()` in `PrepareOrderCorrectionJob::orderIsModified()` (base class) and `CorrectModifiedOrderJob::startOrFail()` + `doubleCheck()`.** `Order::price` accessor strips trailing zeros (`'1.4769'`) while `reference_price` returns the raw DECIMAL(20,8) string (`'1.47690000'`); strict `!==` produces false-drift on numerically equal pairs, which would re-fire `apiModify` on no-op corrections. Masked in production by the upstream `OrderObserver::checkForOrderModification` already using `Math::equal`, so the broken gate never saw equal pairs in the happy path. Bitget variant of `PrepareOrderCorrectionJob` already used the correct pattern; base now matches.
- [BUG FIX] **`ExchangeSymbol::delistedAt()` return type widened from `?\Illuminate\Support\Carbon` to `?\Carbon\CarbonInterface`.** Project's date factory returns `CarbonImmutable`, which does NOT extend `Illuminate\Support\Carbon` — every call on a delisted symbol would TypeError. Latent because the trait method has zero callers in production code today; kept for the audit-trail use case the docblock describes.
- [BUG FIX] **`Concerns/Order/HasStatuses` dead methods removed.** `updateToCancelled()` and `updateToFailed()` wrote to `error_message`, which is not a column on the `orders` table (lives on positions only). Both methods had zero callers across the codebase. Trait emptied; if order-level error annotation is needed later, add the column first.
- [BUG FIX] **TRIGGERED algo-status faithfulness.** Binance algo SL fires surface as `algoStatus=TRIGGERED` on the exchange; the canonical mapping in `MapsPlaceAlgoOrder::mapAlgoStatus` previously folded TRIGGERED into FILLED, erasing the distinction between an SL-fired event and a routine cancel/fill on the audit trail. Now mapped faithfully (`'TRIGGERED' => 'TRIGGERED'`). Companion changes: `OrderObserver::updating()` pin prevents downstream code paths from clobbering TRIGGERED back to CANCELLED on subsequent syncs; close-trigger types include TRIGGERED alongside FILLED; `dispatchClosePosition` writes `reference_status = $model->status` (was hardcoded `'FILLED'`, which infinite-recursed observer fires when status was TRIGGERED because the upstream early-return `status === reference_status` check kept failing); `CancelAlgoOpenOrdersJob::INACTIVE_STATUSES` now includes `'TRIGGERED'` so a finished algo on the exchange is treated as inactive on the local cancel-cascade.
- [BUG FIX] **Stop-loss placement no longer requires a LIMIT order to anchor against.** `PlaceStopLossOrderJob::resolveAnchorPrice()` (and Bitget's `PlacePositionTpslJob` mirror) now falls back to `position->opening_price` when `total_limit_orders === 0`, so simple-trade mode (1 MARKET + 1 TP + 1 SL, no DCA ladder) ships a working SL. Previously `lastLimitOrder()` returned null and the placement step refused to fire, leaving the position unprotected on the exchange.

### Improvements

- [IMPROVED] **`get_market_order_amount_divider($totalLimitOrders)` returns `1` for `N <= 0`.** The historic `2^(N+1)` curve was tuned for ladder reservations and would otherwise leave half the capital sitting idle for rungs that never get placed in simple-trade mode.

### Features

- [NEW FEATURE] **Migration `2026_05_08_213000_install_trading_step_dispatcher_prefix.php`.** Idempotently calls `steps:install --prefix=trading` so test-DB `migrate:fresh` runs install the trading-prefix table set without depending on a separate manual install step.

## 1.34.4 - 2026-05-08

### Features

- [NEW FEATURE] **`FleetFinancials::winRate(Window): array{count, win_rate_pct}`** — single-round-trip per-trade win-rate aggregate sourced from `positions`. Filters: `status='closed'` AND `closed_at` in window AND non-null `profit_percentage` AND `profit_percentage != 0`. Wins = `profit_percentage > 0`; break-even trades are excluded entirely (neither numerator nor denominator) so a 0% trade leaves the win-rate untouched. Returns `win_rate_pct = null` when the window has zero non-break-even closes (UI hides the strip). Replaces the hand-rolled `closedPositionsStats()` query that used to live in `kraite.com/PublicStatsController`.

### Improvements

- [IMPROVED] **`FleetFinancials::daysInProfit(Window)` widened to all-account scope.** Drops the previous `whereIn('account_id', $fleet->ids())` filter so the marketing-site green/red-day count covers every cleanly-closed position in the database, not just active+tradeable accounts. Brings it onto the same scope as the new `winRate()` so the two strips on the marketing site reconcile (sanity invariant: `winRate.count >= daysInProfit.observed`).

## 1.34.3 - 2026-05-08

### Improvements

- [IMPROVED] **`AccountFinancials` and `FleetFinancials` realized metrics now sourced from closed-position trade PnL, not raw wallet snapshots.** Every "how is account / fleet doing in window Y" number — `realizedDelta`, `realizedRoiPct`, `dailyRevenues`, `dailyPercentages`, `scenarios`, `daysInProfit`, `shareInProfit`, `dailySparkline`, and the projection that consumes them — is immune to deposits, withdrawals, funding fees, exchange-side rebates, and any other non-trading wallet movement. Per-position absolute PnL = `profit_percentage / 100 × margin`; rows are filtered to `status='closed'` AND `closed_at` inside the window AND non-null `profit_percentage`/`margin` so cancelled / failed / still-active positions never paint a misleading day. Wallet snapshots stay as the projection anchor (today's actual money to compound forward) and as the denominator for ROI / daily-pct math, but they no longer drive realized numerator. Verified against prod: account #1 last-7d wallet drift was +17.16 USDT vs trade-only PnL +33.66 USDT — the ~16 USDT delta is exactly the cash-flow contamination this rewrite removes.

## 1.34.2 - 2026-05-08

### Features

- [NEW FEATURE] **Trade-critical workflows split onto the `trading_*` dispatcher prefix.** Every entry point that creates trade-critical steps now wraps the `Step::create()` site in `Steps::usingPrefix('trading', …)` so opens, sync orders, close, drift heals, WAP, order corrections, and user-data-stream events all land on the `trading_steps_*` table set. Calculation churn (klines, indicators, BSCS, balance snapshots, leverage brackets, market regime) keeps running on the default `steps_*` set. Two dispatcher fleets in parallel against one MySQL DB; isolated saturation, retention, and cooldown per fleet.
  - `SyncOrdersCommand` (cron `kraite:cron-sync-orders`) — full per-position dispatch loop wrapped
  - `CreatePositionsCommand` (cron `kraite:cron-create-positions`) — full account loop wrapped (orphan recovery + slot opening)
  - `CheckDriftsCommand` (cron `kraite:cron-check-drifts`) — both heal dispatch sites (`PrepareSyncOrdersJob`, `PrepareCancelOrphanOrdersJob`)
  - `StreamBinanceUserDataCommand` daemon — per-event `ProcessUserDataEventJob` create wrapped tightly (NOT lifetime — the long-running ReactPHP loop pushes only for the duration of each frame)
  - `OrderObserver` — five sites (close, replacement, WAP, partial-fill quantity sync, order correction); each wrap spans BOTH the dedupe SELECT and the INSERT so step-level dedupe queries the trading table
  - `PositionObserver::updated` — `PurgePositionTrailJob` on close
  - `Concerns\Order\HandlesChanges` trait — three-step WAP block (status → WAP → status); whole block lands in trading so the dispatcher's index-ordered execution stays consistent for the block_uuid
- [NEW FEATURE] **Per-prefix `MaintenanceMode`.** `pauseStepsDispatch($reason, $ttl, ?string $prefix = null)` accepts a third argument: `null` = all-scope (backward compat — existing `OptimizeBreadcrumbTablesCommand` no-arg call still freezes everything); `''` = default dispatcher only; `'trading'` = trading dispatcher only. `isStepsDispatchPaused(?string $prefix = null)` returns true when EITHER the all-scope flag OR the matching per-prefix flag is set, so the all-scope acts as a global override. Real ops cases unlocked: drain default backlog while trading keeps managing live positions; pause trading for an exchange API maintenance window without freezing klines/indicators.

### Improvements

- [IMPROVED] **Notification noise gated under prefix-aware pauses.** `SendStaleStepsNotification` listener reads `RuntimeContext::current()` to determine the watchdog's prefix and skips the `group_no_progress` Pushover when THAT prefix is paused (the other two reasons keep firing — they're real worker-died signals). `CheckSystemHealthCommand` skips the indicator + balance freshness checks under default-prefix pause. `CheckDriftsCommand` skips the entire run under trading-prefix pause (Scope 3's `allow_opening_positions=false` flip on a stale view was the worst-case hazard).

## 1.33.0 - 2026-05-08

### Features

- [NEW FEATURE] **Dispatcher saturation tracking.** New `steps_dispatcher_saturation` table (per-group, per-minute aggregate) plus `kraite:cron-flush-dispatcher-saturation` command that pulls the per-tick Redis counters written by step-dispatcher 1.11.14 into the persistent table. Reads the *previous* completed minute so it never races with in-flight ticks; consumed Redis keys are deleted after flush (their natural 90s TTL is the backstop). Saturation % = `ticks_capped_with_leftover / ticks_observed × 100`. New `Kraite\Core\Models\StepsDispatcherSaturation` model with a `saturation_pct` accessor. Dashboard surface to be wired in admin.

## 1.32.0 - 2026-05-08

### Fixes

- [BUG FIX] **Stale mark-price freshness gate in token discovery.** `HasTokenDiscovery::executeTokenAssignment()` now drops candidate symbols whose sidecar `mark_price_synced_at` is older than `kraite.token_discovery.mark_price_max_age_seconds` (default 30) BEFORE scoring. When the price daemon stalls (Binance WS hiccup, daemon crash, frame loss), every reader of mark_price returns the last value silently. Token discovery uses mark_price for the wrong-side-pivot check; sizing uses it as the divisor turning notional intent into MARKET order quantity. Acting on a 60-second-stale price across many accounts in the same tick is the structural risk this gate exists to prevent. A general daemon stall now produces zero opens across every account in the same tick instead of a wave of stale-priced bad picks. Null sidecar (legacy column path / brand-new symbol / test fixture) is allowed through; the throwing computations in `HasTradingComputations` catch the null-everything case downstream as defence in depth.

### Improvements

- [IMPROVED] **Per-account fan-out blast-radius hardening across 6 cron / orchestrator points.** A single account's exception during a slot-rotation tick used to abort every subsequent account in the iteration. Wrapped each per-account / per-position / per-rung loop in a try/catch + log + continue pattern: `CreatePositionsCommand` (per-account), `SyncOrdersCommand` (per-position), `DispatchPositionSlotsJob` (per-position), `DispatchAccountBalancesJob` (per-account), `DispatchLimitOrdersJob` (per-rung), `CheckSystemHealthCommand` (per-account balance probe). One bad row no longer poisons the whole tick.
- [IMPROVED] **Indexed orphan / live-step lookup** in `CreatePositionsCommand` and `DispatchPositionSlotsJob`. Switched from the unindexed `whereJsonContains('arguments->positionId', $id)` predicate to an indexed `(relatable_type, relatable_id, state)` tuple covered by `idx_p_steps_rel_state_idx`, with a JSON OR-fallback for the brief Pending-without-relatable transition window. `Step::create` now populates `relatable_type` / `relatable_id` explicitly so the indexed path is hot.
- [IMPROVED] **N+1 single-query refactor** in `CreatePositionsCommand` — replaced the per-user `accounts()` lazy load with a single eager-loaded `Account::whereHas('user')` query.
- [IMPROVED] Counters and structured logging on the hardened fan-out points (`positions_dispatched` / `positions_failed` / `rungs_dispatched` / `rungs_failed`) so a partial-tick recovery is observable rather than silent.

### Configuration

- [NEW FEATURE] `kraite.token_discovery.mark_price_max_age_seconds` config key (env `TOKEN_DISCOVERY_MARK_PRICE_MAX_AGE_SECONDS`, default 30). Set to 0 to disable the freshness gate.

## 1.31.0 - 2026-05-07

### Features

- [NEW FEATURE] **`PurgePositionTrailJob` atomic janitor.** Deletes the diagnostic breadcrumb trail (model_logs, api_request_logs, api_snapshots, steps, steps_archive) for a position whose lifecycle ended cleanly (`status='closed'` only — `cancelled` and `failed` exits keep their full forensic trail). Single DB transaction; covers both Position-bound and Order-bound polymorphic rows; excludes the janitor's own running step row from the steps deletion so the job can complete and be archived normally. Fired automatically by the new `PositionObserver::updated()` hook on the `closed` transition; idempotent on re-run. Targets the three biggest 24h-growth tables (model_logs +534k/day, steps +432k/day, api_request_logs +237k/day). Verified end-to-end against prod: dispatched manually for Position #1223 (SOLUSDT) → 1496 breadcrumb rows wiped in <1s, position row + 7 orders untouched. Bulk fan-out across all 359 already-closed positions cleared 412,339 rows total in 25s.
- [NEW FEATURE] **`OptimizeBreadcrumbTablesCommand` + per-table managed maintenance window.** New `kraite:cron-optimize-breadcrumb-tables` rebuilds breadcrumb-table `.ibd` files via `OPTIMIZE TABLE`, reclaiming the disk space InnoDB DELETE leaves stranded inside the file. Each rebuild runs inside its own maintenance window: `MaintenanceMode::pauseStepsDispatch()` engages → wait for in-flight Running/Dispatched steps to drain (60s cap) → run the OPTIMIZE → resume in finally (cache-TTL safety net auto-resumes if the process crashes mid-rebuild). Sequential per-table (parallel mode tripled per-table duration in production benchmarks because all 5 rebuilds shared the same disk bandwidth).
- [NEW FEATURE] **`Kraite\Core\Support\MaintenanceMode` helper.** Cache-backed flag (Redis in prod, array in tests) for short-window pause/resume of `steps:dispatch`. Default 30-minute TTL safety net guarantees auto-recovery if the consumer crashes before clearing the flag. Public surface: `pauseStepsDispatch(reason, ttlSeconds)`, `resumeStepsDispatch()`, `isStepsDispatchPaused()`, `stepsDispatchPauseInfo()`. Designed as a generic gating point so any future short-maintenance command (table rebuilds, schema migrations, bulk reseeds) can reuse the same pattern.

### Improvements

- [IMPROVED] **`PositionObserver::updated()` wired to dispatch the breadcrumb janitor.** Previously the observer only ran the `creating()` UUID stamp; the new `updated()` hook is gated by `wasChanged('status')` AND `status === 'closed'` so it only fires once per clean lifecycle exit. `cancelled` and `failed` exits keep their full diagnostic trail untouched.

### Features

- [NEW FEATURE] **Position structure integrity audit (`kraite:cron-check-drifts` Scope 3).** New 5-minute spotter scope walks every `active` position and verifies the live order set matches the position's snapshot promise: 1 MARKET entry + N LIMIT (N = `total_limit_orders`) + 1 PROFIT-* take profit + 1 STOP-MARKET stop loss, all in non-terminal status (i.e. not CANCELLED / EXPIRED / REJECTED). DB-only check — trusts the user-data-stream + sync-orders pipeline to keep `orders.status` fresh, so it works identically across Binance and Bitget. NO quiet window: a position only enters `active` after its full order set is in place, so any gap on `active` is a real anomaly that must surface immediately. On any gap: globally halts new opens by flipping `kraite.allow_opening_positions = false` (HasTradingGuards already enforces the flag across every dispatch path) and sends one `position_structure_broken` notification per broken position. Throttled per position id via the existing notification cache-key mechanism so each broken incident produces a single ping. Recovery is operator-driven: inspect, fix, flip the flag back. Verified end-to-end against prod by manually CANCELLING a TP row on Position #101 (XRPUSDT) — audit detected, all 3 channels (Pushover #2169, Mail #2170, Telegram #2171) fired, flag flipped, state restored cleanly. New `--skip-structure-audit` flag exists for tests that intentionally fixture broken structures.
- [NEW FEATURE] **`position_structure_broken` notification canonical.** Critical severity, priority 1 (bypasses Pushover quiet hours). Email body lists every missing component with a [CMD]-tagged inspection query; Pushover body summarises the missing components inline so the operator can act from the lock screen.
- [NEW FEATURE] **`SyncPositionQuantityFromExchangeJob` atomic.** Pulls the live position size from the exchange and overwrites `positions.quantity` with `abs(size)`. Strictly scoped — only rewrites quantity, never touches opening_price, breakeven, profit_percentage, or any TP/SL. Skips silently when the position is no longer active (close workflow owns the row) or when no matching position is on the exchange snapshot (closed externally). Dispatched on every observed LIMIT PARTIALLY_FILLED transition so the local mirror tracks the running net size between the initial MARKET fill and the eventual full-fill WAP refresh — eliminates the inter-chunk drift window where the local quantity lagged the real position size.

### Fixes

- [BUG FIX] **Cross-exchange "already closed" recognition in `ClosePositionAtomicallyJob`.** Position #755 (TONUSDT) and #803 (CAKEUSDT) — both TP-fill close paths — were mismarked `failed` on 2026-05-06 because the prior handler raised `NonNotifiableException` on Binance's `-2022 ReduceOnly Order is rejected`. The new handler treats `-2022` (Binance), `22002` / "No position to close" (Bitget flash close), and known Bybit / Kucoin variants all as authoritative "nothing to reduce" signals — the position has been flattened (TP/SL fill, manual close, prior cancel cascade) and the close call is a harmless confirmation. All four signals collapse to the `already_closed=true` shape the legacy snapshot pre-flight returned, restoring the natural TP-close exit path across every exchange.
- [BUG FIX] **BSCS recovery no longer re-fires every tick.** `AnalyseBscsJob` left `bscs_cooldown_until` populated with the past timestamp after release, so every subsequent tick re-entered the `score < threshold AND hadCooldown` branch and re-dispatched `market_regime_recovered` — the operator received a recovery ping every minute until the score climbed again. The release path now nulls `bscs_cooldown_until` so the next tick correctly reads the state as "no cooldown" and silently no-ops. Pinned by a regression test in `AnalyseBscsJobTest.php`.
- [BUG FIX] **MARKET-CANCEL orphan cleanup.** `Position::placeMarketCancelOrder()` (in `InteractsWithApis`) speculatively `Order::create()`'d a NEW MARKET-CANCEL row before calling `apiPlace()`. When `apiPlace()` threw on a Binance `-2022` rejection ("nothing to reduce" — already flattened), the local row was never deleted — orphan NEW MARKET-CANCELs accumulated on every TP-fill close path with no `exchange_order_id`, never syncing, cluttering the orders table. The throw path now `$order->delete()`s the speculative row before re-raising, leaving the table clean while preserving the caller's exception-driven flow.

### Improvements

- [IMPROVED] **Partial-fill quantity-sync belt-and-suspenders for Bitget.** The `OrderObserver` LIMIT-PARTIALLY_FILLED hook already dispatches `SyncPositionQuantityFromExchangeJob` on every observed transition. That covers Binance: its REST mapper writes `executedQty` into `orders.quantity`, dirtying the row on every chunk and re-firing the observer. Bitget's mapper falls back to `size` because the V2 detail endpoint omits `filledQty`, so the row stays clean across chunks 2..N — the observer never re-fires and the local quantity drifts. `PrepareSyncOrdersJob` now also dispatches the quantity-sync atomic whenever the position has at least one PARTIALLY_FILLED LIMIT, deduped against the same Pending / Dispatched / Running step window the observer uses. Net: local `positions.quantity` tracks Bitget partial fills correctly between the first chunk and the eventual full-fill WAP refresh.

## 1.29.0 - 2026-05-06

### Improvements

- [IMPROVED] **`Position::apiClose()` rewritten to use local DB truth.** The legacy implementation called `apiQueryPositions()` first and built the close order from `positionAmt` / `positionSide` returned by the exchange. Binance's REST positions endpoint lags the WS trade ledger by 5-20s under burst load, and during the Position #577 (TONUSDT, 2026-05-06) cancel cascade the snapshot was empty when `apiClose()` queried it — the foreach iterated zero rows, no close was sent, and the position sat naked on the exchange until a later position's close cycle absorbed it as collateral. The new path: a one-shot `Position::buildCloseOrderAttributes()` helper sums every FILLED MARKET + LIMIT row to derive the close quantity, derives `side` from the position's `direction`, and stamps `position_side` from `direction` (the place-order mappers branch on `account->isHedgeMode()` and either inject positionSide or set reduceOnly=true, so the same Order row is correct in both modes). When local DB shows nothing FILLED the helper returns null and `apiClose()` returns an empty `ApiResponse` without firing a request. Also picks up any LIMIT rung that filled mid-cancel-cascade (rare but real on a fast price drop) — the SUM means the close flattens the full local position, not just the MARKET entry.
- [IMPROVED] **`ClosePositionAtomicallyJob` snapshot pre-flight removed.** The 23-line `apiQueryPositions()` + `positionExistsOnExchange` early-return block at the top of `computeApiable()` was the same race as `apiClose()` (often back-to-back queries within the same job, both seeing the stale ledger). Removed entirely. The existing Binance `-2022 ReduceOnly rejected` handler is now the authoritative "exchange genuinely has nothing to reduce" sentinel — it already throws `NonNotifiableException` for operator reconcile, which is the right outcome when local DB and exchange disagree.

## 1.28.0 - 2026-05-06

### Fixes

- [BUG FIX] **MARKET reference_price drift no longer kicks the cancel-cascade.** `ActivatePositionJob::validateMarketOrders()` was running `validateReferenceFields()` on the MARKET entry, requiring `reference_price === price` at 8-decimal precision. The exchange determines the MARKET fill price (slippage + multi-trade VWAP); local `reference_price` is an informational snapshot from the first sync after `PlaceMarketOrderJob` — it isn't a value we ever set or modify. When Binance returned a multi-trade VWAP that didn't snap to the tick the mark-price snapshot was rounded to (6 of the last 293 MARKET fills, all sub-cent), the validator threw, the lifecycle entered `CancelPositionJob`, and the cancel cascade raced Binance's positions ledger — leaving a residual position naked on the exchange (Position #577 TONUSDT, 2026-05-06). Drift checks remain on LIMIT/PROFIT-LIMIT/STOP-MARKET (we set those prices; drift = real third-party-modify signal).

## 1.27.0 - 2026-05-06

### Features

- [NEW FEATURE] **Binance user-data daemon notification canonicals (6).** Adds the message-builder entries the daemon dispatches against: `binance_user_data_account_connected` (Info — per-account WebSocket opened), `binance_user_data_account_init_failed` (High — per-account init blew up; other accounts unaffected; retried on the next 60s discovery sweep), `binance_user_data_listen_key_expired` (Medium — Binance pushed `listenKeyExpired`; daemon auto-re-inits; frequent firings flag aggressive key invalidation), `binance_user_data_account_reaped` (Info — account no longer eligible; per-account socket closed and listenKey row deleted), `binance_user_data_memory_restart` (Medium — daemon exceeded memory ceiling; supervisor respawn), `binance_listen_key_keepalive_failed` (High — three consecutive keepalive failures; without refresh the listenKey hits Binance's 60-minute hard expiry).

## 1.26.0 - 2026-05-05

### Fixes

- [BUG FIX] **Market regime notifications activated.** `MarketRegimeNotificationsSeeder` previously seeded `market_regime_critical`, `market_regime_recovered`, and `market_regime_compute_stale` with `is_active = false` (Phase 1 telemetry-only stub). Result: when `AnalyseBscsJob` armed a real cooldown (BSCS score ≥ 80), the call site dispatched the canonical but `NotificationService` resolved no DB row and silently dropped the alert — the operator never learned the cooldown had armed. Today's 07:55 cooldown-arm event was masked exactly this way. Seeder now ships all three rows `is_active=true, verified=true`; smoke-tested end-to-end (3 channels delivered).

### Improvements

- [IMPROVED] **Binance prices daemon: persistence write-rate ÷5.** `StreamBinancePricesCommand` now coalesces `exchange_symbol_prices` writes to `WRITE_INTERVAL_SECONDS = 5` (down from per-tick at 1s cadence). Binance's `!markPrice@arr@1s` stream still feeds the in-memory price map every second so freshness is unchanged for any reader that consumes the in-memory snapshot, but DB persistence drops from ~2,500 row writes/sec across the 2,257-symbol universe to ~500/sec. Local read paths (slot allocation, dashboard, stress-fill, BSCS) tolerate single-digit-second staleness; exchange-side TP / SL / limit triggers operate independently of `mark_price` so the persistence cadence has no effect on order safety.

## 1.25.0 - 2026-05-04

### Features

- [NEW FEATURE] **`exchange_symbol_prices` sidecar table.** Splits the hot `mark_price` + `mark_price_synced_at` pair out of `exchange_symbols` into a dedicated 1:1 narrow table so the 1-second-cadence price daemon's bulk UPDATE no longer contends with every other writer of `exchange_symbols` (correlation/elasticity refresh, indicator pipeline, etc.). Phase A cutover: new table + INSERT...SELECT backfill; daemon write paths cut over in the same release; old columns on `exchange_symbols` stay populated as a historical snapshot until a future migration drops them after parity soak.
- [NEW FEATURE] **`kraite:purge-old-data` command.** Single artisan command that purges `api_request_logs` (5-day retention) + `model_logs` (30-day retention) on a daily schedule; replaces the standalone `purge-model-logs` schedule entry. Chunked deletes (default 1000 rows/batch) avoid long table locks; `--dry-run` previews counts.
- [NEW FEATURE] **Lifecycle scenario tables (4).** Backing schema for the admin position-lifecycle configurator — `lifecycle_scenarios`, `lifecycle_scenario_tokens` (with `frozen_config` JSON snapshot), `lifecycle_scenario_frames`, `lifecycle_scenario_frame_events`. Event-sourced semantics: each frame stores user events; computed state is always derived, never persisted.

### Improvements

- [IMPROVED] **`api_request_logs.payload` nulled on 200-class responses.** `BaseApiClient::recordSuccessfulResponse()` now sets `payload = null` once `http_response_code < 400`. Successful payloads provide no forensic value and dominated row size (~70%); errors keep the original payload because they take a different code path. Combined with the new 5-day retention, hot table size dropped 14 GB → 2 GB after backfill + OPTIMIZE on production.
- [IMPROVED] **`mark_price_synced_at` index dropped from `exchange_symbol_prices`.** Per-tick rewrite of ~500 secondary-index entries was the dominant cost behind the 100s mark-price UPDATE blocker. Watchdog read on a 2.3K-row, 0.16 MB table is essentially free without the index — net win on every tick.
- [IMPROVED] **`#0 mark-price freshness` health check joins through the sidecar.** Eligibility predicates (tradeable / open-position) stay on `exchange_symbols`; freshness filter targets `exchange_symbol_prices.mark_price_synced_at` post-cutover.

## 1.24.0 - 2026-05-04

### Features

- [NEW FEATURE] **`kraite:recover-positions` upgraded to true bidirectional disaster-recovery.** Phase 1 (existing exchange → local gap-fill) is now followed by four new phases that turn the command from a "gap-filler" into a "true-up". Phase 2 walks every LOCAL opened-status position; flips to `closed` if the (account, symbol, direction) key isn't in the exchange snapshot (positions closed during the disaster gap). Safety guard: empty exchange snapshot + non-empty local set is treated as transient API failure (no mass-close). Phase 3 walks every local non-terminal order on still-active positions; calls `apiSync()` per row to mirror current exchange status (orders cancelled / filled / expired during the gap get reflected locally). Phase 4 walks every position stuck in `opening` / `syncing` / `cancelling`; resets to `active` if exchange shows the position, `closed` otherwise (workflow-crash recovery). Phase 5 ships operational guards: `kraite.allow_opening_positions=false` flip with try/finally restore; pre-recovery `mysqldump` of positions + orders to `/tmp/kraite-recovery-{ts}.sql`; new `recovery_completed` notification canonical with run summary. Lost-history accepted per Bruno's call — positions opened+closed entirely within the gap are NOT recreated.
- [NEW FEATURE] **`recovery_completed` notification canonical.** Seeded via migration `2026_05_04_140000_seed_recovery_completed_notification`. Severity `info`, no throttling (intrinsically once-per-recovery). Fires at the end of a successful (non-dry-run) `kraite:recover-positions` with the summary stats — accounts checked / OK / skipped, positions created / updated / skipped, orders created / skipped, snapshot path, warnings count. Reaches every channel in the admin's `notification_channels` array (Pushover + Mail + Telegram).

### Fixes

- [BUG FIX] **OrderObserver dispatch dedupe race fixed.** `dispatchClosePosition`, `dispatchPositionReplacement`, `dispatchApplyWap` and `ProcessUserDataEventJob::maybeDetectManualPositionClose` all wrap their SELECT-then-INSERT in a `DB::transaction` with `Position::query()->whereKey($id)->lockForUpdate()->first()` BEFORE the dedupe-and-insert pair. The first horizon worker to enter the transaction holds the row lock; every subsequent worker blocks until commit, then sees the freshly-inserted Step and skips. Atomic across every worker, every server, every connection. Background: ETC #211 incident on 2026-05-03 — Binance pushed four `ORDER_TRADE_UPDATE` frames simultaneously, four parallel workers all passed the dedupe check and inserted duplicate `PreparePositionReplacementJob` steps for the same position, two replacement workflows ran in parallel, killed the TP, duplicated LIMIT rungs.
- [BUG FIX] **`position_opened` state drift resolved.** `KraiteSeeder` now sets `is_active=false` on the `position_opened` canonical entry — matches the commented-out `dispatchOpenedNotification()` call in `ActivatePositionJob::complete()` (TEMP mute on Bruno's call — too chatty on a 12-slot book). Source-of-truth aligned: canonical says off, code is off, seeder enforces off. Re-enable both when adding a digest / quiet-hours filter.

## 1.23.0 - 2026-05-04

### Features

- [NEW FEATURE] **Telegram as a third notification channel.** `AlertNotification` exposes `toTelegram()` alongside the existing `toMail()` / `toPushover()` methods. Channel selection driven by the user's `notification_channels` array (string `'telegram'` resolves to `\NotificationChannels\Telegram\TelegramChannel::class` via the `User` accessor). Routing target: `users.telegram_chat_id` (new nullable column) for real users, `kraite.admin_telegram_chat_id` (new nullable column) for the virtual admin path through `Kraite::admin()`. HTML parse-mode, escape-safe. Message body falls back `telegramMessage → pushoverMessage → message` so canonicals get a sensible default without authoring three variants. End-to-end live verified via `test:notification slow_query_detected` — Pushover + Mail + Telegram all delivered.
- [NEW FEATURE] **Auto-welcome on first chat_id pairing.** `UserObserver` watches `users.telegram_chat_id` transitions (created with a value OR null→set on update) and fires a one-off `sendMessage` directly through the Telegram Bot API. `KraiteObserver` mirrors the behaviour for `kraite.admin_telegram_chat_id`. Welcome body explicitly states the channel is one-way ("alerts only — replies aren't read"). Failure-contained: bad token / Telegram 401 / network blip → log + swallow, never aborts the surrounding model save.
- [NEW FEATURE] **Disk-pressure health check.** Twelfth check on `CheckSystemHealthCommand` (`checkDiskPressure`). Reads `disk_free_space('/')` / `disk_total_space('/')`; emits `disk_pressure_low` signal when free < 15%. Body includes free / total / percent breakdown so the operator can `du -sh` straight from the alert. Logs grow faster than DB rows on this server (`horizon.log` 43 MB with .1..10 rotated copies); disk pressure shows up before any other capacity signal.

### Improvements

- [IMPROVED] **`KraiteSeeder` seeds `admin_telegram_chat_id` from `ADMIN_USER_TELEGRAM_CHAT_ID` env via `kraite.admin_user_telegram_chat_id` config key.** Mirrors the existing pushover-key flow.

## 1.22.0 - 2026-05-04

### Fixes

- [BUG FIX] **`ProcessUserDataEventJob::applyToOrderModel` no longer overwrites `orders.quantity` from WS pushes.** The previous shape wrote `event->filledQuantity` (cumulative-executed amount) into the local `orders.quantity` column — which is supposed to hold the ORIGINAL placed quantity. During multi-fill MARKETs on thin books, intermediate `ORDER_TRADE_UPDATE` PARTIALLY_FILLED frames carry e.g. `filledQuantity=18.5` of an `originalQuantity=81.9` order, and that 18.5 was overwriting the local row's 81.9 — only to be later "corrected" back to 81.9 on the FILLED frame, with out-of-order PARTIALLY_FILLED frames re-corrupting it again. The OrderObserver-side capture of `reference_quantity` could land at any moment in that window, persisting a stale value. `ActivatePositionJob::validateReferenceFields` then threw `MARKET order quantity drift: reference_quantity=18.5, quantity=81.9` and tipped the lifecycle into the cancel-cascade for a position that had actually filled correctly on the exchange. Reproduced 2026-05-04 04:48 on ONDOUSDT position #271 — close-cascade flat-closed the long near breakeven, fees ate the rest = negative trade. Fix: WS push only updates `status` and `price` (from `averagePrice`); `quantity` stays frozen at placement. Cumulative fill progress already lives in `api_data_stream` rows. Pinned with four TDD cases covering PARTIALLY_FILLED corruption, FILLED no-op, out-of-order regression, and the happy NEW→FILLED progression.

## 1.21.0 - 2026-05-04

### Fixes

- [BUG FIX] **`CancelPositionOpenOrdersJob` now iterates per-order on every exchange.** The previous shape used `cancelAllOpenOrders(symbol)` on Binance / Kucoin / Bybit (per-order on Bitget already), which Binance interprets as a symbol-wide DELETE — wiping every working order for that symbol regardless of which Kraite position owns it. On 2026-05-03 22:50 the drift watchdog dispatched a cancel-lifecycle for position 209 (already cancelled, with stuck NEW orders); the symbol-wide DELETE killed the freshly-active position 211's TP and 4 LIMIT ladder as collateral damage. New shape: load the position's own `is_algo=false` orders with status ∈ {`NEW`, `PARTIALLY_FILLED`} and a non-null `exchange_order_id`, bump `reference_status='CANCELLED'` BEFORE the API calls (the OrderObserver's intent gate — when the matching cancellation lands via WS push, `status===reference_status` and the replacement dispatch correctly stays quiet), then iterate `Order::apiCancel()` per row. "Already gone" responses (Binance `-2011` "Unknown order sent" et al) are classified by `BaseExceptionHandler::ignoreException()` and treated as idempotent. Symbol-wide collateral damage eliminated by construction; no race window vs sequential open-close-reopen of the same symbol. Smoke-tested live with manual close on LTCUSDT (position 227) — four targeted `DELETE /fapi/v1/order` calls, zero `/fapi/v1/allOpenOrders`, position closed cleanly.

## 1.20.0 - 2026-05-03

### Improvements

- [IMPROVED] **`CheckDriftsCommand` pivots to alert-only with surgical silent self-heal.** The drift watchdog no longer dispatches `PrepareSyncOrdersJob` (Scope 1) or `PrepareCancelOrphanOrdersJob` (Scope 2), and no longer inline-cancels "ghost" rows in the local DB. Scope 1 just notifies on quiet active-position drift; Scope 2 runs a single silent `apiSync()` per orphan candidate that carries an `exchange_order_id` before deciding to alert. Candidates whose post-sync status flips to terminal (FILLED / CANCELLED / EXPIRED) drop out automatically — no notification, no lifecycle dispatch. Enforcement remains in `CheckSystemHealthCommand::checkOrphanReconciliation` (7-minute cadence, auto-cancel + auto-close); drift (5-minute cadence) is the faster monitor — it alerts first on a fresh orphan; Health follows up with the auto-cancel on its next tick. Background: 2026-05-03 evening incidents (#208 + #209) traced auto-action overlap between drift Scope 1 and the live sync-orders cron + WS push path. Pulling drift back to alert-only removes the overlap.
- [IMPROVED] **Reverts: pre-flight `apiSync` in orphan-orders pass + `PreparePositionReplacementJob` step-arguments dedupe scan.** The 1.19.0 pre-flight `apiSync` fired one REST call per orphan candidate even when the row was already terminal on the exchange — under live load it tipped the orphan-orders pass into a hot loop on `steps` + `orders`. The dedupe scan in `PreparePositionReplacementJob` used `whereJsonContains('arguments->positionId', ...)` over the 700K-row `steps` table on every horizon worker startup; the unindexed JSON predicate held row locks long enough to wedge the mark-price daemon's ReactPHP loop on a 192-second wait against `exchange_symbols` (~3-minute stale-price outage at 14:37 UTC). Both reverted in full. The drift command's surgical silent self-heal (Scope 2) replaces the pre-flight `apiSync` with bounded post-classification calls only on candidates that pass the quiet-window filter.

### Fixes

- [BUG FIX] **`ActivatePositionJob` MARKET validation is race-tolerant.** The strict-FILLED guard on the entry MARKET is replaced with a poll-with-timeout: up to three attempts at 500ms apart, each calling `apiSync()` to refresh the local row from exchange truth. If the exchange-side state confirms FILLED, the row promotes and the lifecycle proceeds. Only if the exchange itself still reports a non-terminal state after the bounded budget do we surface the legitimate error. Background: `PlaceMarketOrderJob` writes the local row from the REST ack, which can return at PARTIALLY_FILLED for a multi-level fill on a thin book; Binance follows up with an `ORDER_TRADE_UPDATE` push that promotes the row to FILLED, but under DB-lock contention or a wedged WS daemon that promotion can lag by hundreds of milliseconds — long enough to throw position #208 + #209 into the cancel-cascade for fills that had actually completed correctly on the exchange. Three TDD cases pin the behaviour (already-FILLED, exhausted budget, mid-poll promotion).

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
