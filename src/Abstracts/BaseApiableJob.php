<?php

declare(strict_types=1);

namespace Kraite\Core\Abstracts;

use Exception;
use Kraite\Core\Concerns\BaseApiableJob\HandlesApiJobExceptions;
use Kraite\Core\Concerns\BaseApiableJob\HandlesApiJobLifecycle;
use Kraite\Core\Exceptions\NonNotifiableException;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ForbiddenHostname;
use Kraite\Core\Models\Server;
use Kraite\Core\Support\NotificationService;
use Kraite\Core\Support\Proxies\ApiThrottlerProxy;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Failed;
use Throwable;

abstract class BaseApiableJob extends BaseQueueableJob
{
    use HandlesApiJobExceptions;
    use HandlesApiJobLifecycle;

    public ?BaseExceptionHandler $exceptionHandler;

    abstract public function computeApiable();

    protected function compute()
    {
        if (! method_exists($this, 'assignExceptionHandler')) {
            throw new Exception('Exception handler not instanciated!');
        }

        $this->assignExceptionHandler();

        $apiSystemCanonical = $this->exceptionHandler->getApiSystem();
        $apiSystem = ApiSystem::where('canonical', $apiSystemCanonical)->firstOrFail();
        $accountId = $this->exceptionHandler->account->id;
        $ipAddress = \Kraite\Core\Models\Kraite::ip();

        // Load every active ban touching this (account, api_system) pair —
        // both account-scoped rows and system-wide rows. One query, walked
        // in PHP for the type-based branches below.
        //
        // Active = forbidden_until is NULL (no expiry) or > now (window
        // still open). Rotation treats both as blocking.
        $activeBans = ForbiddenHostname::query()
            ->where('api_system_id', $apiSystem->id)
            ->where(static function ($query) use ($accountId): void {
                if ($accountId === null) {
                    $query->whereNull('account_id');

                    return;
                }
                $query->where('account_id', $accountId)->orWhereNull('account_id');
            })
            ->where(static function ($query): void {
                $query->whereNull('forbidden_until')
                    ->orWhere('forbidden_until', '>', now());
            })
            ->get();

        // account_blocked = bad/revoked/disabled API key. Switching IP cannot
        // help — the credentials themselves are the problem. Deactivate the
        // account immediately so the cron stops fanning to it and the user
        // is alerted that their portfolio is unmanaged. Fires regardless of
        // whether the ban was discovered on this worker or another.
        $hasAccountBlocked = $activeBans->contains(
            static fn (ForbiddenHostname $ban): bool => $ban->type === ForbiddenHostname::TYPE_ACCOUNT_BLOCKED
        );

        if ($hasAccountBlocked) {
            $this->deactivateAccountAndFail($apiSystem);

            return;
        }

        // Rotation-eligible bans are IP-bound (each fleet IP has its own
        // whitelist status / rate-limit budget / penalty-box status on the
        // exchange). The current worker may be blacklisted while siblings
        // are still clean — re-route rather than burn retries locally.
        $rotationBannedIps = $activeBans
            ->whereIn('type', [
                ForbiddenHostname::TYPE_IP_NOT_WHITELISTED,
                ForbiddenHostname::TYPE_IP_RATE_LIMITED,
                ForbiddenHostname::TYPE_IP_BANNED,
            ])
            ->pluck('ip_address')
            ->unique()
            ->all();

        if (in_array($ipAddress, $rotationBannedIps, true)) {
            $cleanWorkers = Server::query()
                ->where('is_apiable', true)
                ->where('needs_whitelisting', true)
                ->whereNotNull('own_queue_name')
                ->whereNotIn('ip_address', $rotationBannedIps)
                ->get();

            if ($cleanWorkers->isNotEmpty()) {
                $target = $cleanWorkers->random();
                $this->rotateToQueue((string) $target->own_queue_name);

                return;
            }

            // No clean worker exists. Terminal cascade is reserved for
            // permanent bans (ip_not_whitelisted) — those need user action
            // to clear and won't recover on their own. Temporary bans
            // (ip_rate_limited / ip_banned with a future expiry) just need
            // to wait out the window; preserve the original retry behaviour
            // so the step naturally retries once the ban auto-expires.
            $hasPermanentBan = $activeBans->contains(
                static fn (ForbiddenHostname $ban): bool => $ban->type === ForbiddenHostname::TYPE_IP_NOT_WHITELISTED
            );

            if ($hasPermanentBan) {
                $this->deactivateAccountAndFail($apiSystem);

                return;
            }

            $this->retryJob();

            return;
        }

        try {
            return $this->computeApiable();
        } catch (Throwable $e) {
            // Let the API-specific exception handler deal with the error.
            $this->handleApiException($e);
        }
    }

    /**
     * Automatic API throttling.
     * Checks if the API system has a throttler and enforces rate limits.
     * Also performs pre-flight safety checks (IP bans, rate limit proximity).
     */
    protected function shouldStartOrThrottle(): bool
    {
        $stepId = $this->step->id;

        // Ensure exception handler is assigned before safety checks
        if (! isset($this->exceptionHandler)) {
            $this->assignExceptionHandler();
        }

        // 0. Exception handler's pre-flight safety check (e.g. banned host,
        // exchange cooldown). Seconds-precision is fine here — these are
        // environmental problems (account blocked, IP banned) that don't
        // need millisecond retry timing.
        if (isset($this->exceptionHandler) && ! $this->exceptionHandler->isSafeToMakeRequest()) {
            $this->jobBackoffSeconds = 5;
            $this->jobBackoffMs = 0;

            Step::log($stepId, 'throttled', sprintf(
                'Throttled by %s::isSafeToMakeRequest | wait=5s | reason=handler_not_safe',
                class_basename($this->exceptionHandler)
            ));

            return false;
        }

        $throttler = $this->getThrottlerForApiSystem();

        if (! $throttler) {
            return true;
        }

        // Per-account context (e.g. Binance UID-based ORDER limits).
        $accountId = $this->exceptionHandler->account?->id;

        // 1. Exchange-specific pre-flight (min-delay between requests,
        // IP-ban status, Binance weight proximity). Returns **milliseconds**
        // so sub-second deficits reschedule at their exact remainder.
        if (method_exists($throttler, 'isSafeToDispatch')) {
            $msToWait = $throttler::isSafeToDispatch($accountId, $stepId);

            if ($msToWait > 0) {
                $msToWait = $this->applyThrottleJitter($msToWait);
                $this->jobBackoffMs = $msToWait;
                $this->jobBackoffSeconds = 0;

                Step::log($stepId, 'throttled', sprintf(
                    'Throttled by %s::isSafeToDispatch | wait=%dms | account_id=%s',
                    class_basename($throttler),
                    $msToWait,
                    $accountId ?? 'null'
                ));

                return false;
            }
        }

        // 2. Base-class check — window cap + min-delay. Returns milliseconds.
        $retryCount = $this->step->retries ?? 0;
        $msToWait = $throttler::canDispatch($retryCount, $accountId, $stepId);

        if ($msToWait > 0) {
            $msToWait = $this->applyThrottleJitter($msToWait);
            $this->jobBackoffMs = $msToWait;
            $this->jobBackoffSeconds = 0;

            Step::log($stepId, 'throttled', sprintf(
                'Throttled by %s::canDispatch | wait=%dms | retry_count=%d | account_id=%s',
                class_basename($throttler),
                $msToWait,
                $retryCount,
                $accountId ?? 'null'
            ));

            return false;
        }

        return true;
    }

    /**
     * Get the throttler class for the current API system.
     * Returns null if no throttler exists (graceful degradation).
     */
    protected function getThrottlerForApiSystem(): ?string
    {
        if (! isset($this->exceptionHandler)) {
            $this->assignExceptionHandler();
        }

        $apiSystem = $this->exceptionHandler->getApiSystem();

        return ApiThrottlerProxy::getThrottler($apiSystem);
    }

    /**
     * Override shouldExitEarly to inject API throttling checks
     * into the lifecycle before standard BaseQueueableJob checks.
     */
    protected function shouldExitEarly(): bool
    {
        // Run standard lifecycle checks first
        if (! $this->shouldStartOrStop()) {
            $this->stopJob();

            return true;
        }

        if (! $this->shouldStartOrFail()) {
            throw new NonNotifiableException("startOrFail() returned false for Step ID {$this->step->id}");
        }

        if (! $this->shouldStartOrSkip()) {
            $this->skipJob();

            return true;
        }

        if (! $this->shouldStartOrRetry()) {
            $this->retryJob();

            return true;
        }

        // API throttle check - ensures rate limits are respected across all workers
        if (! $this->shouldStartOrThrottle()) {
            $this->rescheduleWithoutRetry();

            return true;
        }

        // Check max retries AFTER all lifecycle checks
        $this->checkMaxRetries();

        return false;
    }

    /**
     * Override to automatically record API dispatch before executing.
     */
    protected function executeJobLogic(): void
    {
        if ($this->step->double_check === 0) {
            // Automatically record API dispatch BEFORE calling computeApiable()
            $throttler = $this->getThrottlerForApiSystem();

            if ($throttler) {
                // Extract account ID for per-account rate limit tracking
                $accountId = $this->exceptionHandler->account?->id;
                $stepId = $this->step->id;
                $throttler::recordDispatch($accountId, $stepId);
            }

            // Now call the parent implementation
            parent::executeJobLogic();
        }
    }

    /**
     * Hook: Delegate retry decisions to the API exception handler.
     */
    protected function externalRetryException(Throwable $e): bool
    {
        return isset($this->exceptionHandler)
            && method_exists($this->exceptionHandler, 'retryException')
            && $this->exceptionHandler->retryException($e);
    }

    /**
     * Hook: Delegate ignore decisions to the API exception handler.
     */
    protected function externalIgnoreException(Throwable $e): bool
    {
        return isset($this->exceptionHandler)
            && method_exists($this->exceptionHandler, 'ignoreException')
            && $this->exceptionHandler->ignoreException($e);
    }

    /**
     * Hook: Delegate exception resolution to the API exception handler.
     */
    protected function externalResolveException(Throwable $e): void
    {
        if (isset($this->exceptionHandler)
            && method_exists($this->exceptionHandler, 'resolveException')
        ) {
            $this->exceptionHandler->resolveException($e);
        }
    }

    /**
     * Override to provide API-specific diagnostic information for retry failures.
     *
     * Mirrors compute()'s ban-resolution scope: includes account-scoped AND
     * system-wide active bans for the current API system. Branches the message
     * by ban type so operators reading max-retry logs see the actual cause
     * (whitelist gap, rate-limit window, system-wide ban, account block) rather
     * than a hardcoded "not whitelisted" string that's wrong for 3 of 4 types.
     *
     * @return array<int, string>
     */
    protected function getRetryDiagnostics(): array
    {
        $diagnostics = [];

        try {
            if (! isset($this->exceptionHandler)) {
                $this->assignExceptionHandler();
            }

            $apiSystemCanonical = $this->exceptionHandler->getApiSystem();
            $apiSystem = ApiSystem::where('canonical', $apiSystemCanonical)->first();

            if (! $apiSystem) {
                return $diagnostics;
            }

            $exchangeName = $apiSystem->name ?? $apiSystemCanonical;
            $ipAddress = \Kraite\Core\Models\Kraite::ip();
            $accountId = $this->exceptionHandler->account?->id;

            $bans = ForbiddenHostname::query()
                ->where('api_system_id', $apiSystem->id)
                ->where('ip_address', $ipAddress)
                ->where(static function ($query) use ($accountId): void {
                    if ($accountId === null) {
                        $query->whereNull('account_id');

                        return;
                    }

                    $query->where('account_id', $accountId)->orWhereNull('account_id');
                })
                ->where(static function ($query): void {
                    $query->whereNull('forbidden_until')
                        ->orWhere('forbidden_until', '>', now());
                })
                ->get();

            foreach ($bans as $ban) {
                $diagnostics[] = $this->buildBanDiagnostic($ban, $exchangeName, $ipAddress);
            }
        } catch (Throwable $e) {
            // Silently skip diagnostics if anything fails
        }

        return $diagnostics;
    }

    /**
     * Terminal sink for the rotation engine. Fires in two cases:
     *  1. The exchange returned account_blocked (bad API key — no IP rotation
     *     can fix it).
     *  2. Every apiable worker IP is blacklisted (rotation exhausted).
     *
     * Disables the account so downstream cronjobs / dispatchers stop fanning
     * steps to it, marks the current step Failed, and fires a critical
     * "portfolio at risk" notification to the account owner so they know
     * to investigate immediately — the system can no longer manage their
     * open positions until they intervene on the exchange side.
     */
    private function deactivateAccountAndFail(ApiSystem $apiSystem): void
    {
        $account = $this->exceptionHandler->account;
        $reason = sprintf(
            'All worker IPs blacklisted on %s — fix whitelist/API key and reactivate',
            $apiSystem->name
        );

        $wasActive = $account->is_active;

        if ($wasActive) {
            $account->update([
                'is_active' => false,
                'disabled_reason' => $reason,
                'disabled_at' => now(),
            ]);

            // Loud user notification — distinct from the per-ban
            // ForbiddenHostnameObserver notifications. The portfolio is
            // unmanaged until the user intervenes, so this fires at
            // Critical severity and bypasses Pushover quiet hours.
            // Throttled by (account_id, api_system) to one-per-hour to
            // avoid spam if rotation triggers across many concurrent
            // steps for the same account, while still re-notifying if
            // the user re-activates and the same failure happens again
            // an hour later.
            $owner = $account->user;
            if ($owner !== null) {
                NotificationService::send(
                    user: $owner,
                    canonical: 'account_all_workers_blacklisted',
                    referenceData: [
                        'apiSystem' => $apiSystem,
                        'account' => $account,
                        'api_system' => $apiSystem->canonical,
                        'account_id' => $account->id,
                        'account_name' => $account->name,
                    ],
                    relatable: $account,
                    cacheKeys: [
                        'account_id' => $account->id,
                        'api_system' => $apiSystem->canonical,
                    ]
                );
            }
        }

        $this->step->update(['error_message' => $reason]);
        $this->step->state->transitionTo(Failed::class);
        $this->stepStatusUpdated = true;
    }

    /**
     * Spread the reschedule so concurrent workers don't all land on the
     * same dispatch_after.
     *
     * The throttler math `last_dispatch + min_delay` produces an identical
     * target instant for every worker racing the cache at the same time —
     * they all compute the same "earliest-allowed" moment regardless of
     * when they actually checked. Without jitter the dispatcher releases
     * them as a single herd, the throttler admits one, and the rest pile
     * onto the next identical target. The cycle traps throughput well
     * below the configured cap.
     *
     * Added jitter is 0–30% of the base wait with a 50 ms floor so even
     * tiny deficits gain breathing room. Jitter only pushes the reschedule
     * later, never earlier, so it cannot cause 429s.
     */
    private function applyThrottleJitter(int $msToWait): int
    {
        $jitterMax = max(50, (int) ($msToWait * 0.3));

        return $msToWait + random_int(0, $jitterMax);
    }

    /**
     * Format a single ForbiddenHostname row as a triage-friendly string.
     */
    private function buildBanDiagnostic(ForbiddenHostname $ban, string $exchangeName, string $ipAddress): string
    {
        $scope = $ban->account_id === null ? 'system-wide' : "account #{$ban->account_id}";
        $until = $ban->forbidden_until?->toDateTimeString();
        $untilSuffix = $until !== null ? " until {$until}" : '';

        return match ($ban->type) {
            ForbiddenHostname::TYPE_IP_NOT_WHITELISTED => "IP {$ipAddress} is not whitelisted on {$exchangeName} ({$scope}). Add this IP to the API key whitelist.",

            ForbiddenHostname::TYPE_IP_RATE_LIMITED => "IP {$ipAddress} is rate-limited on {$exchangeName} ({$scope}){$untilSuffix}. Auto-recovers when the window expires.",

            ForbiddenHostname::TYPE_IP_BANNED => "IP {$ipAddress} is banned on {$exchangeName} ({$scope}){$untilSuffix}. Contact {$exchangeName} support if persistent.",

            ForbiddenHostname::TYPE_ACCOUNT_BLOCKED => "Account #{$ban->account_id} is blocked on {$exchangeName}. Check API key validity and permissions.",

            default => "Forbidden hostname on {$exchangeName} ({$scope}, type={$ban->type}, ip={$ipAddress}){$untilSuffix}.",
        };
    }
}
