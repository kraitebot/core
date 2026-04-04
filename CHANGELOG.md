# Changelog

All notable changes to this project will be documented in this file.

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
