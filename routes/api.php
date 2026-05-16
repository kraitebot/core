<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Kraite\Core\Http\Controllers\Api\ConnectivityTestController;
use Kraite\Core\Http\Controllers\Webhooks\NotificationWebhookController;

/**
 * Core Package Webhook Routes
 *
 * These routes receive POST requests from external notification gateways.
 * CSRF protection is disabled for these routes (configured in bootstrap/app.php).
 */

// Zeptomail webhook endpoint
// Receives: hard bounce, soft bounce, open events
// Accepts GET for Zeptomail's verification test, POST for actual webhooks
Route::match(['get', 'post'], '/webhooks/zeptomail/events', [NotificationWebhookController::class, 'zeptomail'])
    ->middleware('throttle:30,1')
    ->name('webhooks.zeptomail');

// Pushover receipt callback endpoint
// Receives: emergency notification acknowledgment
Route::post('/webhooks/pushover/receipt', [NotificationWebhookController::class, 'pushover'])
    ->middleware('throttle:10,1')
    ->name('webhooks.pushover');

/**
 * Connectivity Test Routes
 *
 * Used during user registration to test API credentials from all apiable servers.
 * Tests which server IPs can connect to exchange APIs before account creation.
 */

// Get connectivity test status by block_uuid.
// Returns progress and results of all server connectivity tests. Authenticated
// because the response includes per-server error_message values that leak
// operational state and credential-validation outcomes.
Route::get('/connectivity-test/status/{blockUuid}', [ConnectivityTestController::class, 'status'])
    ->middleware(['auth', 'throttle:30,1'])
    ->name('connectivity-test.status');

// Start connectivity checks for an existing account. Used by the admin
// console account panel; it creates a parent step and one child per API
// execution server.
Route::post('/connectivity-test/accounts/{account}/start', [ConnectivityTestController::class, 'startAccount'])
    ->middleware(['auth', 'throttle:6,1'])
    ->name('connectivity-test.accounts.start');

// Send the user a per-server whitelist notification after a failed result.
Route::post('/connectivity-test/accounts/{account}/notify-server', [ConnectivityTestController::class, 'notifyAccountServer'])
    ->middleware(['auth', 'throttle:10,1'])
    ->name('connectivity-test.accounts.notify-server');
