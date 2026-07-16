<?php

declare(strict_types=1);

namespace Kraite\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Kraite\Core\Support\FreezeMode;
use Symfony\Component\HttpFoundation\Response;

final class BlockExternalTrafficWhenFrozen
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! FreezeMode::isActive() || in_array($request->ip(), ['127.0.0.1', '::1'], true)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Kraite is frozen. External traffic is disabled.',
        ], Response::HTTP_SERVICE_UNAVAILABLE);
    }
}
