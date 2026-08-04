<?php

declare(strict_types=1);

namespace Kraite\Core\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Kraite\Core\Models\Account;
use Kraite\Core\Support\Connectivity\AccountServerConnectivityService;

/**
 * ConnectivityTestController
 *
 * Tests exchange connectivity from every API-capable server.
 *
 * Every endpoint authorizes against the `operate` ability on the target
 * Account (owner-or-admin) — these actions touch the account's exchange
 * credentials, fire owner-facing whitelist notifications, and expose
 * per-server topology / credential-validation state, so authentication
 * alone is not sufficient.
 */
final class ConnectivityTestController extends Controller
{
    public function startAccount(Account $account, AccountServerConnectivityService $connectivity): JsonResponse
    {
        Gate::authorize('operate', $account);

        return response()->json($connectivity->start($account));
    }

    public function status(string $blockUuid, AccountServerConnectivityService $connectivity): JsonResponse
    {
        Gate::authorize('operate', $connectivity->ownerAccount($blockUuid));

        return response()->json($connectivity->status($blockUuid));
    }

    public function notifyAccountServer(Request $request, Account $account, AccountServerConnectivityService $connectivity): JsonResponse
    {
        Gate::authorize('operate', $account);

        $validated = $request->validate([
            'server_id' => ['required', 'integer', 'exists:servers,id'],
        ]);
        $server = $connectivity->apiConnectivityServer((int) $validated['server_id']);

        if ($server === null) {
            throw ValidationException::withMessages([
                'server_id' => 'The selected server is not available for connectivity checks.',
            ]);
        }

        $sent = $connectivity->notify($account, $server);

        return response()->json([
            'sent' => $sent,
            'message' => $sent ? 'Notification sent to user.' : 'Notification was not sent.',
        ]);
    }
}
