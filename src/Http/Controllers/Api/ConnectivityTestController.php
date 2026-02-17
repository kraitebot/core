<?php

declare(strict_types=1);

namespace Kraite\Core\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Kraite\Core\Jobs\Models\Server\TestConnectivityOnServerJob;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\Server;
use StepDispatcher\Models\Step;

/**
 * ConnectivityTestController
 *
 * Tests user-provided exchange credentials from all apiable servers
 * during registration to identify which server IPs can connect to exchange APIs.
 *
 * Workflow:
 * 1. POST /start - Create test steps for all apiable servers with same block_uuid
 * 2. GET /status/{blockUuid} - Poll to check completion status of all test steps
 */
final class ConnectivityTestController extends Controller
{
    /**
     * Start connectivity test for user-provided credentials.
     *
     * Creates test steps for ALL apiable servers, dispatching jobs to their
     * own_queue_name to test from that server's IP.
     *
     * Used during registration before user account is created.
     *
     * @return JsonResponse {
     *                      block_uuid: string,
     *                      total_servers: int,
     *                      message: string
     *                      }
     */
    public function start(Request $request): JsonResponse
    {
        // Get valid exchanges dynamically from ApiSystem
        $validExchanges = ApiSystem::where('is_apiable', true)
            ->pluck('canonical')
            ->toArray();

        $validated = $request->validate([
            'exchange' => ['required', 'string', Rule::in($validExchanges)],
            'credentials' => ['required', 'array'],
            'credentials.*' => ['required', 'string'],
        ]);

        /** @var string $exchangeCanonical */
        $exchangeCanonical = $validated['exchange'];
        /** @var array<string, string> $credentials */
        $credentials = $validated['credentials'];

        // Verify exchange exists
        $apiSystem = ApiSystem::where('canonical', $exchangeCanonical)->firstOrFail();

        // Generate block_uuid for this test run
        $blockUuid = Str::uuid()->toString();

        // Get all apiable servers
        $apiableServers = Server::where('is_apiable', true)->get();

        if ($apiableServers->isEmpty()) {
            return response()->json([
                'error' => 'No apiable servers configured',
            ], 500);
        }

        // Create test step for EACH apiable server
        foreach ($apiableServers as $server) {
            $job = new TestConnectivityOnServerJob(
                credentials: $credentials,
                exchangeCanonical: $exchangeCanonical,
                serverHostname: $server->hostname,
                serverIp: $server->ip_address ?? 'unknown'
            );

            // Create step with block_uuid for grouping (no user relation since user doesn't exist yet)
            $step = Step::create([
                'class' => TestConnectivityOnServerJob::class,
                'title' => "Test connectivity from {$server->hostname}",
                'description' => "Testing {$exchangeCanonical} API connectivity from {$server->hostname} ({$server->ip_address})",
                'block_uuid' => $blockUuid,
                'state' => 'pending',
                'relatable_type' => null,
                'relatable_id' => null,
            ]);

            // Dispatch job to server's queue
            $job->runAsStep($step, $server->own_queue_name);
        }

        return response()->json([
            'block_uuid' => $blockUuid,
            'total_servers' => $apiableServers->count(),
            'message' => "Connectivity test started for {$apiableServers->count()} servers",
        ]);
    }

    /**
     * Get status of connectivity test by block_uuid.
     *
     * Returns ALL steps for this block_uuid with their current state, response, and errors.
     *
     * @return JsonResponse {
     *                      block_uuid: string,
     *                      total_steps: int,
     *                      completed_steps: int,
     *                      failed_steps: int,
     *                      pending_steps: int,
     *                      is_complete: bool,
     *                      steps: array[{
     *                      id: int,
     *                      title: string,
     *                      state: string,
     *                      response: object|null,
     *                      error_message: string|null
     *                      }]
     *                      }
     */
    public function status(string $blockUuid): JsonResponse
    {
        // Get all steps for this block_uuid
        $steps = Step::where('block_uuid', $blockUuid)
            ->orderBy('id', 'asc')
            ->get();

        if ($steps->isEmpty()) {
            return response()->json([
                'error' => 'No test found for this block_uuid',
            ], 404);
        }

        // Calculate progress
        $totalSteps = $steps->count();
        $completedSteps = $steps->where('state', 'completed')->count();
        $failedSteps = $steps->where('state', 'failed')->count();
        $pendingSteps = $steps->whereIn('state', ['pending', 'processing'])->count();
        $isComplete = $pendingSteps === 0;

        // Format steps for response
        $stepsData = $steps->map(static function (Step $step) {
            return [
                'id' => $step->id,
                'title' => $step->title,
                'description' => $step->description,
                'state' => $step->state,
                'response' => $step->response,
                'error_message' => $step->error_message,
                'created_at' => $step->created_at->toIso8601String(),
                'updated_at' => $step->updated_at->toIso8601String(),
            ];
        });

        return response()->json([
            'block_uuid' => $blockUuid,
            'total_steps' => $totalSteps,
            'completed_steps' => $completedSteps,
            'failed_steps' => $failedSteps,
            'pending_steps' => $pendingSteps,
            'is_complete' => $isComplete,
            'steps' => $stepsData,
        ]);
    }
}
