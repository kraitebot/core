<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Recovery;

use Kraite\Core\Contracts\ClientLevelApiThrottler;
use Kraite\Core\Models\Account;
use Kraite\Core\Support\Proxies\ApiThrottlerProxy;

/**
 * RecoveryApiThrottle
 *
 * Routes a recovery-flow exchange API call through the SAME per-IP
 * rate-limit gate that `BaseApiableJob::shouldStartOrThrottle()` enforces
 * for normal trading. Recovery historically called the exchange directly,
 * bypassing the throttler — fine for one account, a guaranteed IP ban at
 * fleet scale.
 *
 * Each call: wait until the throttler says the IP is safe (min-delay +
 * ban status + weight proximity), then record the dispatch and fire.
 * Response-weight headers are recorded automatically inside
 * `BaseApiClient` on a successful response, so we do not do that here.
 *
 * The throttler is IP-scoped in Redis (`Kraite::ip()`), so when recovery
 * runs as fan-out jobs across the worker fleet each box coordinates on
 * its own IP budget — the whole point of distributing recovery.
 */
final class RecoveryApiThrottle
{
    /** Cap a single sleep so a wedged throttler value can't park a call forever. */
    private const MAX_SLEEP_MS = 5000;

    /** Defensive upper bound on wait loops (~20 min at the sleep cap) before firing anyway. */
    private const MAX_LOOPS = 240;

    /**
     * Gate one exchange API call for the account's exchange through its
     * throttler, then execute it. Falls straight through when the
     * exchange has no throttler registered.
     */
    public static function call(Account $account, callable $apiCall, int|string|null $stepId = null): mixed
    {
        /** @var class-string|null $throttler */
        $throttler = ApiThrottlerProxy::getThrottler($account->apiSystem->canonical);

        if ($throttler === null || is_subclass_of($throttler, ClientLevelApiThrottler::class)) {
            return $apiCall();
        }

        $accountId = $account->id;
        $loops = 0;

        while ($loops++ < self::MAX_LOOPS) {
            $waitMs = $throttler::isSafeToDispatch($accountId, $stepId);

            // Atomic throttlers reserve inside canDispatch(). Do not call it
            // while the exchange-specific gate is already asking us to wait,
            // otherwise every wait-loop iteration consumes a request slot.
            if ($waitMs <= 0) {
                $waitMs = $throttler::canDispatch(0, $accountId, $stepId);
            }

            if ($waitMs <= 0) {
                break;
            }

            usleep(min($waitMs, self::MAX_SLEEP_MS) * 1000);
        }

        $throttler::recordDispatch($accountId, $stepId);

        return $apiCall();
    }
}
