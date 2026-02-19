# Changelog

All notable changes to this project will be documented in this file.

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
