<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns\BaseApiableJob;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Kraite\Core\Models\Account;
use Throwable;

/*
 * HandlesApiJobExceptions
 *
 * • Trait for BaseApiableJob classes to handle API-specific exceptions.
 * • Detects throttling, connectivity, and known RecvWindow issues.
 * • Automatically retries failed jobs by deferring dispatch with backoff.
 * • Triggers custom command to adjust recvWindow timing when applicable.
 * • Sets step state to Pending and flags it as updated after deferral.
 */
trait HandlesApiJobExceptions
{
    protected function handleApiException(Throwable $e): void
    {
        // Notifications are sent by ApiRequestLogObserver after log is persisted

        // Handle connection failures like timeouts or DNS issues.
        if ($e instanceof ConnectException) {
            $this->retryDueToNetworkGlitch();

            return;
        }

        if ($e instanceof RequestException) {
            // Position-mode mismatch is checked FIRST so it never gets
            // miscategorised as an IP-ban, rate limit, or recvWindow drift.
            // Listens for the family of position-side error codes Binance
            // documents (-4060, -4061, -4062, -4067) — covers asymmetric
            // variants and protects against a Binance-side rename. The
            // catch flips `accounts.on_hedge_mode` and reschedules the
            // failing step so the next dispatcher tick retries with the
            // corrected payload, with no Position::updateToFailed cascade
            // and no symbol auto-block.
            if ($this->isPositionModeMismatch($e)) {
                $this->autoFlipPositionMode();
                $this->rescheduleWithoutRetry(now()->addSeconds(2));

                return;
            }

            if ($this->exceptionHandler->ignoreException($e)) {
                // Ignorable exceptions (like 400 Bad Request for invalid symbols)
                // Job completes normally, allowing computeApiable() to return its result
                return;
            }

            if ($this->exceptionHandler->isRecvWindowMismatch($e)) {
                $this->handleRecvWindowIssue($e);

                return;
            }

            // Case 1: IP not whitelisted by user (user-fixable)
            if ($this->exceptionHandler->isIpNotWhitelisted($e)) {
                $this->exceptionHandler->forbidIpNotWhitelisted($e);
                $this->retryJob(); // Put job back in queue for another worker

                return;
            }

            // Case 2: IP temporarily rate-limited (auto-recovers)
            // Check BEFORE generic isRateLimited() to ensure ForbiddenHostname record is created
            if ($this->exceptionHandler->isIpRateLimited($e)) {
                $this->exceptionHandler->forbidIpRateLimited($e);
                $this->retryJob(); // Put job back in queue for when ban expires

                return;
            }

            // Generic rate limiting (not IP-specific)
            if ($this->exceptionHandler->isRateLimited($e)) {
                $this->retryPerApiThrottlingDelay($e);

                return;
            }

            // Case 3: IP permanently banned for ALL accounts
            if ($this->exceptionHandler->isIpBanned($e)) {
                $this->exceptionHandler->forbidIpBanned($e);
                $this->retryJob(); // Put job back in queue for another worker/server

                return;
            }

            // Case 4: Account blocked (API key issue)
            if ($this->exceptionHandler->isAccountBlocked($e)) {
                $this->exceptionHandler->forbidAccountBlocked($e);
                $this->retryJob(); // Put job back in queue (won't help until user fixes key)

                return;
            }
        }

        // Re-throw if it's not handled by any of the above.
        throw $e;
    }

    protected function handleRecvWindowIssue($e): void
    {
        // Lets improve the recvwindow safety for a higher duration.
        try {
            Artisan::call('kraite:update-recvwindow-safety-duration');
        } catch (Throwable $commandException) {
            // Command might fail in test environment or when API is unavailable
            // We'll still retry with existing recvwindow_margin
        }

        $this->retryPerApiThrottlingDelay($e);
    }

    protected function retryPerApiThrottlingDelay(Throwable $e): void
    {
        /*
         * Set a future dispatch_after time and mark the step as pending.
         * This defers job execution based on rateLimiter's exchange policy.
         * Uses rescheduleWithoutRetry() because rate limits are not failures.
         */
        $retryAt = $this->exceptionHandler->rateLimitUntil($e);

        // Record a fleet-wide IP ban ONLY when the exchange has given us an
        // explicit ban signal. Plain 429s without a Retry-After header are
        // per-request probes — the exchange is pushing back on THIS call, not
        // banning the IP. Writing a global ban on every such 429 converts the
        // per-step backoff into a synchronized fleet halt; when the ban lifts,
        // every queued worker stampedes at once, trips the rate limit again,
        // and the cycle oscillates (observed on KuCoin/Bitget leverage-bracket
        // bursts). TAAPI, which defines no `recordIpBan` method, doesn't have
        // this amplifier and drains at its cap with smooth per-step retries —
        // that's the behaviour we want here too.
        if ($e instanceof RequestException
            && $e->hasResponse()
            && $this->isExplicitIpBanSignal($e)
        ) {
            $retryAfterSeconds = (int) max(0, now()->diffInSeconds($retryAt, false));

            if ($retryAfterSeconds > 0) {
                $throttler = $this->getThrottlerForApiSystem();
                if ($throttler && method_exists($throttler, 'recordIpBan')) {
                    $throttler::recordIpBan($retryAfterSeconds);
                }
            }
        }

        // Use rescheduleWithoutRetry() instead of retryJob()
        // Rate limits and recvWindow issues are throttling conditions, not failures
        // This prevents retry counter increment and eventual max retries exhaustion
        $this->rescheduleWithoutRetry($retryAt);
    }

    /**
     * Identify responses that warrant a fleet-wide IP ban in the cache.
     *
     * - 418 / 403: exchanges (Binance / Bybit) use these as hard IP-level
     *   bans with a documented recovery window — a fleet halt is correct.
     * - 429 WITH Retry-After: the exchange is telling us the IP is banned
     *   for N seconds. Honour it globally.
     * - 429 WITHOUT Retry-After: soft per-request rate-limit probe. Backing
     *   off only the failing step is the right shape.
     */
    protected function isExplicitIpBanSignal(RequestException $e): bool
    {
        $statusCode = $e->getResponse()->getStatusCode();

        if (in_array($statusCode, [418, 403], strict: true)) {
            return true;
        }

        if ($statusCode === 429) {
            return $e->getResponse()->getHeaderLine('Retry-After') !== '';
        }

        return false;
    }

    protected function retryDueToNetworkGlitch(): void
    {
        // Just apply a standard rate limiter retry.
        $this->retryJob(now()->addSeconds($this->exceptionHandler->backoffSeconds));
    }

    /**
     * Recognise a position-mode mismatch error across exchanges.
     *
     * Binance (int codes, per error-code reference):
     *   -4060: INVALID_POSITION_SIDE
     *   -4061: POSITION_SIDE_NOT_MATCH (the canonical mismatch error)
     *   -4062: REDUCE_ONLY_CONFLICT (reduceOnly set in the wrong mode)
     *   -4067: POSITION_SIDE_CHANGE_EXISTS_OPEN_ORDERS
     *   -1106: PARAM_NOT_REQUIRED, but ONLY when the offending parameter
     *     is reduceOnly. Hedge mode rejects the reduceOnly parameter with
     *     this GENERIC code instead of a -406x one, so a one-way-flagged
     *     account closing against a real hedge-mode exchange would
     *     otherwise fail into the cancel cascade instead of self-healing.
     *     Gated on the parameter name in the message because -1106 also
     *     fires for unrelated stray params.
     *
     * Bitget (string codes, per V2 docs):
     *   "40774": "The order type for unilateral position must also be the
     *     unilateral position type" — fires when the payload assumes the
     *     wrong position mode (e.g. sending tradeSide=open on a one-way
     *     account, or omitting it on a hedge account).
     *   "43075": "Position pattern mismatch" — current equivalent.
     *
     * Listening for the family rather than the single canonical code per
     * exchange defends against renaming and asymmetric variants. Other
     * exchanges add their own equivalents here when phased (Bybit's
     * positionIdx mismatch, KuCoin's trading-mode error, etc.).
     */
    protected function isPositionModeMismatch(RequestException $e): bool
    {
        if (! $e->hasResponse()) {
            return false;
        }

        $payload = json_decode((string) $e->getResponse()->getBody(), associative: true);

        if (! is_array($payload) || ! array_key_exists('code', $payload)) {
            return false;
        }

        $code = $payload['code'];

        // Binance returns numeric codes (incl. negative). Cast preserves
        // the int form whether code arrived as int or numeric string.
        if (is_numeric($code) && in_array((int) $code, [-4060, -4061, -4062, -4067], strict: true)) {
            return true;
        }

        // Binance -1106 is only a mode mismatch when the rejected
        // parameter is reduceOnly (see docblock above).
        if (is_numeric($code) && (int) $code === -1106) {
            $message = mb_strtolower((string) ($payload['msg'] ?? ''));

            return str_contains($message, 'reduceonly');
        }

        // Bitget returns string codes. Compared as strings to avoid the
        // numeric cast collapsing leading zeros / non-numeric variants.
        if (in_array((string) $code, ['40774', '43075'], strict: true)) {
            return true;
        }

        return false;
    }

    /**
     * Atomically flip the failing job's account on_hedge_mode. Uses
     * lockForUpdate so concurrent failures from sibling atomic jobs
     * (e.g. PlaceLimitOrder rung 2 + PlaceStopLossOrder firing -4061
     * in the same millisecond) serialise on the row lock — second
     * instance sees the post-flip state and no-ops if it already
     * flipped, avoiding thrash.
     *
     * Audit goes to BOTH:
     *   - Log::warning('position_mode_auto_flip', ...) for ops grep
     *   - Account::modelLog('position_mode_auto_flip', ...) for the
     *     per-account forensic timeline visible in the admin UI.
     */
    protected function autoFlipPositionMode(): void
    {
        $account = $this->resolveAccountForPositionModeFlip();

        if (! $account) {
            Log::warning('position_mode_auto_flip_skipped', [
                'reason' => 'no_account_resolved',
                'job_class' => static::class,
            ]);

            return;
        }

        // Bounded auto-flip: refuse a second flip within a 10-minute
        // cooldown window. Pre-fix, repeated mismatch errors on the
        // same account could oscillate `on_hedge_mode` true ↔ false on
        // every retry, since the toggle was a blind invert with no
        // verification against the exchange's actual mode. The
        // cooldown forces an explicit operator review (the warning
        // log + modelLog entries surface the bounce) instead of
        // letting the system fight itself.
        $cooldownKey = "position_mode_auto_flip:cooldown:{$account->id}";
        if (Cache::has($cooldownKey)) {
            Log::warning('position_mode_auto_flip_skipped', [
                'reason' => 'cooldown_active',
                'account_id' => $account->id,
                'job_class' => static::class,
            ]);

            return;
        }

        DB::transaction(function () use ($account, $cooldownKey): void {
            /** @var Account $locked */
            $locked = Account::whereKey($account->id)->lockForUpdate()->first();

            if (! $locked) {
                return;
            }

            // Re-check the cooldown INSIDE the lock. The outer Cache::has
            // check is a fast path, but concurrent sibling failures (a
            // LONG order on one worker + a SHORT on another both firing
            // -4061 in the same millisecond) BOTH pass it before either
            // stamps the key — then they serialise here on lockForUpdate.
            // Without this inner guard the second job blind-inverts the
            // value the first just set, oscillating on_hedge_mode
            // 0→1→0 and landing back on the WRONG mode. With it, the
            // second job sees the cooldown the first committed and
            // no-ops — the "second instance sees the post-flip state"
            // contract this method always claimed. (Live go-live
            // incident 2026-06-06: BAS long + 1000FLOKI short flipped
            // twice in one second, netting back to one-way against a
            // hedge-mode Binance account, so every order kept -4061ing.)
            if (Cache::has($cooldownKey)) {
                Log::warning('position_mode_auto_flip_skipped', [
                    'reason' => 'cooldown_active_post_lock',
                    'account_id' => $locked->id,
                    'job_class' => static::class,
                ]);

                return;
            }

            $previous = (bool) $locked->on_hedge_mode;
            $next = ! $previous;

            // Stamp cooldown inside the transaction so concurrent flips
            // on this account are also blocked once the first commits.
            Cache::put($cooldownKey, true, 600);

            $locked->on_hedge_mode = $next;
            $locked->save();

            Log::warning('position_mode_auto_flip', [
                'account_id' => $locked->id,
                'previous' => $previous,
                'new' => $next,
                'job_class' => static::class,
            ]);

            $locked->modelLog(
                eventType: 'position_mode_auto_flip',
                metadata: [
                    'previous' => $previous,
                    'new' => $next,
                    'job_class' => static::class,
                ],
                message: sprintf(
                    'Position mode auto-flipped: %s → %s (%s returned position-side mismatch on %s)',
                    $previous ? 'hedge' : 'one-way',
                    $next ? 'hedge' : 'one-way',
                    $locked->apiSystem?->canonical ?? 'exchange',
                    class_basename(static::class)
                )
            );
        });
    }

    /**
     * Find the account associated with the failing job. Every
     * BaseApiableJob has an `exceptionHandler` with an `account`
     * reference — that's the canonical source. Returns null if for
     * some reason the handler isn't set up yet.
     */
    protected function resolveAccountForPositionModeFlip(): ?Account
    {
        if (isset($this->exceptionHandler) && $this->exceptionHandler->account instanceof Account) {
            return $this->exceptionHandler->account;
        }

        return null;
    }
}
