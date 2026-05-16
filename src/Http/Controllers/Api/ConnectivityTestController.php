<?php

declare(strict_types=1);

namespace Kraite\Core\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\Server;
use Kraite\Core\Support\Connectivity\AccountServerConnectivityService;

/**
 * ConnectivityTestController
 *
 * Tests exchange connectivity from every API-capable server.
 */
final class ConnectivityTestController extends Controller
{
    public function startAccount(Account $account, AccountServerConnectivityService $connectivity): JsonResponse
    {
        return response()->json($connectivity->start($account));
    }

    public function status(string $blockUuid, AccountServerConnectivityService $connectivity): JsonResponse
    {
        return response()->json($connectivity->status($blockUuid));
    }

    public function notifyAccountServer(Request $request, Account $account, AccountServerConnectivityService $connectivity): JsonResponse
    {
        $validated = $request->validate([
            'server_id' => ['required', 'integer', 'exists:servers,id'],
        ]);

        $sent = $connectivity->notify($account, Server::findOrFail($validated['server_id']));

        return response()->json([
            'sent' => $sent,
            'message' => $sent ? 'Notification sent to user.' : 'Notification was not sent.',
        ]);
    }
}
