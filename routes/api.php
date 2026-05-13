<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Kraite\Core\Http\Controllers\Api\ConnectivityTestController;
use Kraite\Core\Http\Controllers\Api\DashboardApiController;
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

// Start connectivity test for user-provided credentials.
// Creates test steps for all apiable servers and returns block_uuid for polling.
// Requires authentication — the endpoint accepts raw exchange credentials and
// dispatches them to every API-capable server in the fleet, which is a
// credential-bound action that must NOT be reachable anonymously. Pre-fix,
// only IP-based throttle:3,1 protected the route, which let anyone with
// network access probe exchange credential validity at low volume.
Route::post('/connectivity-test/start', [ConnectivityTestController::class, 'start'])
    ->middleware(['auth', 'throttle:3,1'])
    ->name('connectivity-test.start');

// Get connectivity test status by block_uuid.
// Returns progress and results of all server connectivity tests. Authenticated
// because the response includes per-server error_message values that leak
// operational state and credential-validation outcomes.
Route::get('/connectivity-test/status/{blockUuid}', [ConnectivityTestController::class, 'status'])
    ->middleware(['auth', 'throttle:30,1'])
    ->name('connectivity-test.status');

/**
 * Dashboard API Routes
 *
 * Authenticated routes for fetching dashboard data (positions, statistics, charts).
 * Each endpoint polls independently for component-level autonomy.
 */
Route::middleware(['auth', 'throttle:60,1'])->prefix('dashboard')->group(function () {
    // Combined data (legacy, for initial page load)
    Route::get('/data', [DashboardApiController::class, 'index'])
        ->name('api.dashboard.data');

    // Global stats only (poll every 30s)
    Route::get('/stats', [DashboardApiController::class, 'stats'])
        ->name('api.dashboard.stats');

    // All positions list (poll every 10s)
    Route::get('/positions', [DashboardApiController::class, 'positions'])
        ->name('api.dashboard.positions');

    // Single position detail (poll every 5s per card)
    Route::get('/positions/{id}', [DashboardApiController::class, 'position'])
        ->name('api.dashboard.position');
});
