<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Daemons;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Kraite\Core\Jobs\Atomic\UserDataStream\ProcessUserDataEventJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\BinanceListenKey;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Support\ApiClients\Websocket\BinanceApiClient;
use Kraite\Core\Support\Apis\REST\BinanceApi;
use Kraite\Core\Support\FreezeMode;
use Kraite\Core\Support\NotificationService;
use Kraite\Core\Support\ValueObjects\ApiCredentials;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use StepDispatcher\Models\Step;
use StepDispatcher\Support\Steps;
use Throwable;

/**
 * StreamBinanceUserDataCommand
 *
 * Long-running daemon (NOT a cron — runs under supervisor with
 * autostart=true / autorestart=true). Hosts one authenticated WebSocket
 * per Binance account inside a single ReactPHP event loop. Every frame
 * received is dispatched as a Step on the dedicated `user-data` queue
 * and processed by ProcessUserDataEventJob.
 *
 * In v1 (shadow mode), the worker only persists the frame to
 * api_data_stream and logs. The OrderObserver-driven workflow path is
 * implemented but gated behind
 * `kraite.user_data_stream.binance.dispatch_order_updates` (default
 * false). This lets us validate payload extraction against live events
 * before flipping the live-trading switch.
 *
 * Per-account error isolation: a failure on one account's lifecycle
 * (listenKey REST error, WS handshake error, decode failure) is
 * contained — the failing account's slot is dropped and re-attempted
 * on the next discovery sweep. The other accounts' streams keep
 * running. This is the architectural improvement over martingalian's
 * `fatalExit` pattern, which killed the entire daemon on any one
 * account's hiccup.
 *
 * Discovery: a 60-second timer scans the accounts table and inits any
 * Binance account that does not yet have an active client slot. New
 * accounts created at runtime begin streaming within a minute without
 * a daemon restart.
 *
 * Reconnect: BaseWebsocketClient's per-instance idle watchdog +
 * exponential-backoff reconnect handles transient network blips. The
 * close handler also schedules a re-init through the framework so a
 * dropped connection does not need the discovery sweep to recover.
 *
 * listenKeyExpired: if the WS pushes a listenKeyExpired frame, the
 * existing key row is deleted and the account is re-initialised with
 * a fresh listenKey. This is the only daemon-level branch on event
 * type — every other event flows through the unified Step dispatch
 * path.
 */
final class StreamBinanceUserDataCommand extends Command
{
    private const DISCOVERY_INTERVAL_SECONDS = 60;

    /**
     * Cadence at which the daemon checks each account's listen_key in
     * the DB and re-inits the account if it has been rotated since the
     * client was opened. The keepalive cron runs every minute and only
     * rotates on Binance's say-so (rare), so 30s is safe.
     */
    private const ROTATION_CHECK_INTERVAL_SECONDS = 30;

    /**
     * Heartbeat-flag file touch + memory check + reconnect-storm sweep
     * all share this period. Cheap operations, frequent enough that a
     * monitor sees fresh state within a minute.
     */
    private const HEALTH_TICK_INTERVAL_SECONDS = 30;

    /**
     * Account-aware memory ceiling. The daemon's footprint is a fixed
     * base (PHP + Laravel + ReactPHP ~75-150MB) plus a roughly linear
     * per-account cost (~10MB each — one WS client + closures). A FIXED
     * 512MB ceiling was fine at 1 account but would be crossed around
     * ~43 accounts by normal operation, self-exiting the whole daemon
     * and resetting EVERY account's stream — a crash-loop of mass resets
     * at fleet scale. Computing the ceiling from the live account count
     * (see memoryLimitBytes()) means the self-exit fires only on a
     * genuine leak beyond the expected per-account budget, never on
     * healthy load. Generous per-account headroom (25MB vs ~10MB
     * observed) keeps normal growth well clear of a false trip.
     */
    private const MEMORY_BASE_BYTES = 200 * 1024 * 1024;

    private const MEMORY_PER_ACCOUNT_BYTES = 25 * 1024 * 1024;

    /**
     * Per-account connect stagger. On a daemon (re)start every eligible
     * account would otherwise open its WebSocket in the same tick — 100
     * accounts = 100 simultaneous handshakes from athena's single IP,
     * risking Binance's per-IP WS connection rate limit and a
     * thundering-herd reconnect cascade. Spacing each connect by this
     * many seconds ramps the fleet online gradually (0.25s = 4/sec, so
     * 100 accounts spread over ~25s) — comfortably under any connection
     * rate limit while still bringing everyone online quickly. A single
     * steady-state respawn (index 0) gets zero delay.
     */
    private const CONNECT_STAGGER_SECONDS = 0.25;

    private const PROCESS_QUEUE = 'user-data-stream';

    /**
     * Idle threshold tuned for the user-data stream's natural cadence.
     *
     * Binance user-data streams send no frames at all unless an order
     * or account event fires; the only background traffic is a server
     * ping roughly every three minutes (which we auto-pong, refreshing
     * lastFrameAt). The default 30-second threshold from
     * BaseWebsocketClient — designed for the 1Hz mark-price stream —
     * forces a reconnect every quiet window and triggers an admin
     * notification each time.
     *
     * 600 seconds gives multiple ping cycles to occur before the
     * watchdog declares the socket dead, so reconnects happen only
     * on genuinely-stuck connections rather than on natural quiet.
     */
    private const IDLE_TIMEOUT_SECONDS = 600;

    /**
     * Throttle for per-account `binance_listen_keys.last_frame_at`
     * writes. Frames can arrive in bursts on a heavy account; without
     * a throttle every fill would trigger a DB write. 30 seconds is
     * fine-grained enough for ops monitoring without any meaningful
     * cost on volume.
     */
    private const FRAME_HEARTBEAT_THROTTLE_SECONDS = 30;

    protected $signature = 'kraite:stream-binance-user-data';

    protected $description = 'Daemon hosting one authenticated Binance user-data WebSocket per account; dispatches every frame as a Step on the user-data queue.';

    /**
     * Per-account WebSocket slots. Each slot tracks the WS client, the
     * exact listenKey it's currently subscribed to (so rotation can be
     * detected against the DB), and the last-frame timestamp used for
     * the throttled heartbeat write.
     *
     * @var array<int, array{client: BinanceApiClient, listen_key: string, last_frame_persisted_at: float}>
     */
    private array $accountClients = [];

    private ?int $binanceSystemId = null;

    private LoopInterface $loop;

    /**
     * Account ids with a connect scheduled on the loop but not yet
     * slotted. Prevents a second discovery tick (or a re-entrant sweep)
     * from double-scheduling a connect for the same account during the
     * staggered boot ramp, before its slot lands in $accountClients.
     *
     * @var array<int, true>
     */
    private array $spawning = [];

    public function handle(): int
    {
        if (FreezeMode::isActive()) {
            $this->warn('Kraite is frozen. User-data streams waiting without connecting.');

            if (app()->environment('testing')) {
                return self::SUCCESS;
            }

            while (FreezeMode::isActive()) {
                sleep(1);
            }
        }

        $binanceSystem = ApiSystem::active()->canonical('binance')->first();

        if ($binanceSystem === null) {
            $this->error('No api_systems row with canonical=binance — refusing to start.');

            return self::FAILURE;
        }

        $this->binanceSystemId = $binanceSystem->id;

        $loop = Loop::get();
        $this->loop = $loop;

        $loop->addPeriodicTimer(self::DISCOVERY_INTERVAL_SECONDS, function () {
            $this->discoverNewAccounts();
        });

        // Rotation watchdog — the keepalive cron may rotate listenKey
        // for an account; without this loop the daemon's WS would
        // keep using the old key until it expires (60min later) and
        // then enter a never-recovering reconnect loop. We compare
        // the slot's stored listen_key against the DB row and re-init
        // when they diverge.
        $loop->addPeriodicTimer(self::ROTATION_CHECK_INTERVAL_SECONDS, function () {
            $this->checkListenKeyRotations();
        });

        // Combined health tick — touches the heartbeat flag file (so
        // external monitors can detect a wedged loop), checks memory,
        // and runs the reconnect-storm sweep.
        $loop->addPeriodicTimer(self::HEALTH_TICK_INTERVAL_SECONDS, function () {
            if (FreezeMode::isActive()) {
                $this->stopForFreeze();

                return;
            }

            $this->touchHeartbeatFlag();
            $this->checkMemoryPressure();
        });

        $this->discoverNewAccounts();
        $this->touchHeartbeatFlag();

        // Staggered boot: discoverNewAccounts() scheduled a connect per
        // eligible account on the loop rather than opening them inline,
        // so $accountClients is still empty here — the scheduled count
        // lives in $spawning. Emit ONE boot-summary notification for the
        // whole fleet instead of one "connected" alert per account (which
        // at 100 accounts turned every restart into a 100-message storm).
        // Per-account connect success is log-only from here on; only
        // failures (init_failed) and lifecycle events still alert.
        $scheduledCount = count($this->spawning);

        Log::channel('user-data')->info('[USER-DATA] daemon started', [
            'initial_account_count' => $scheduledCount,
            'pid' => getmypid(),
        ]);

        $this->notify(
            'binance_user_data_daemon_online',
            ['account_count' => $scheduledCount, 'pid' => getmypid()],
        );

        $loop->run();

        return self::SUCCESS;
    }

    private function stopForFreeze(): void
    {
        foreach ($this->accountClients as $slot) {
            $slot['client']->close();
        }

        $this->accountClients = [];
        $this->spawning = [];
        $this->loop->stop();
    }

    /**
     * Close the Pawl connection for an account slot and remove the slot
     * from the in-memory map.
     *
     * Used by the rotation and listen-key-expiry paths so the OLD
     * Pawl connection is cleanly torn down before initAccount() opens a
     * fresh one. Without this, the prior client stays alive in the
     * ReactPHP event loop and Binance counts it against the account's
     * stream limit until either the server-side close lands or the
     * idle watchdog acts. With keys rotating every ~60min × N accounts,
     * the orphan-connection rate would otherwise scale linearly with
     * fleet size.
     *
     * Mirrors the close-then-unset shape from reapAccount(), so all
     * three teardown paths (reap, rotation, expiry) now share the
     * same lifecycle. Public visibility is intentional: it lets a unit
     * test inject a synthetic slot, call this method, and assert the
     * close+unset contract without spinning up the ReactPHP loop or
     * issuing a real Binance request.
     */
    public function closeAndUnsetSlot(int $accountId): void
    {
        $slot = $this->accountClients[$accountId] ?? null;
        if ($slot === null) {
            return;
        }

        try {
            if (isset($slot['client']) && is_object($slot['client']) && method_exists($slot['client'], 'close')) {
                $slot['client']->close();
            }
        } catch (Throwable $exception) {
            // Closing a half-dead Pawl connection can throw. The unset
            // below MUST still run — leaving the slot populated with a
            // dead client would block re-init forever.
            Log::channel('user-data')->warning('[USER-DATA] slot close threw', [
                'account_id' => $accountId,
                'error' => $exception->getMessage(),
            ]);
        }

        unset($this->accountClients[$accountId]);
    }

    /**
     * Direct accessors for unit tests. The daemon's in-memory map is
     * the canonical source of truth for "which accounts are streaming";
     * tests need to inject and inspect it without booting the full
     * ReactPHP loop. Both methods are no-ops outside the test path.
     *
     * @internal
     *
     * @param  array<string, mixed>  $slot
     */
    public function injectSlotForTest(int $accountId, array $slot): void
    {
        $this->accountClients[$accountId] = $slot;
    }

    public function hasSlotForTest(int $accountId): bool
    {
        return isset($this->accountClients[$accountId]);
    }

    /**
     * Stagger delay (seconds) for the connect at position $index in a
     * discovery sweep. Linear ramp: index 0 fires immediately, each
     * subsequent account is spaced by CONNECT_STAGGER_SECONDS. Extracted
     * as a pure method so the ramp is unit-testable without the loop.
     */
    public function connectStaggerDelay(int $index): float
    {
        return max(0, $index) * self::CONNECT_STAGGER_SECONDS;
    }

    /**
     * Account-aware memory ceiling: a fixed base plus a per-account
     * budget scaled by the number of live slots. A fixed ceiling would
     * be crossed by normal operation past a few dozen accounts and
     * self-exit the daemon — resetting every account's stream — so the
     * ceiling has to grow with the fleet. Floored at one account's worth
     * so an empty daemon still has the full base budget. Extracted as a
     * pure method so the scaling is unit-testable.
     */
    public function memoryLimitBytes(): int
    {
        $accountCount = max(1, count($this->accountClients));

        return self::MEMORY_BASE_BYTES + (self::MEMORY_PER_ACCOUNT_BYTES * $accountCount);
    }

    /**
     * Walk the accounts table and reconcile the in-process slot set
     * with the DB. Two passes:
     *
     *   1. Reaper — close WS slots whose account is no longer eligible
     *      (deactivated, API key removed, deleted, or marginal/exotic
     *      api_system_id changes). Without this, an admin flipping
     *      `is_active=false` would not stop the daemon from streaming
     *      that account's events until a manual daemon restart.
     *
     *   2. Spawner — open WS slots for any newly-eligible account that
     *      doesn't already have one. Picks up new accounts at runtime
     *      and re-attaches accounts that just flipped back to active.
     *
     * Eligibility: `api_system_id = binance` AND `is_active = true` AND
     * `binance_api_key IS NOT NULL`. Same predicate is enforced in both
     * passes so reap and spawn agree on the eligible set.
     *
     * Per-account try/catch on each pass keeps a single failure from
     * aborting the whole sweep.
     */
    private function discoverNewAccounts(): void
    {
        // Eligibility predicate centralised on the Account model so the
        // daemon's discovery and the keepalive cron's per-row check
        // stay aligned. See Account::scopeEligibleForBinanceUserDataStream
        // for the conditions and rationale.
        $eligibleAccounts = Account::query()
            ->eligibleForBinanceUserDataStream()
            ->get()
            ->keyBy('id');

        // Reap pass: any slot whose account is no longer eligible gets
        // its WS shut and its slot removed. The next eligibility sweep
        // can re-open it cleanly if the account flips back active.
        foreach (array_keys($this->accountClients) as $openId) {
            if ($eligibleAccounts->has($openId)) {
                continue;
            }

            $this->reapAccount((int) $openId);
        }

        // Spawn pass: ensure every eligible account has a slot. Rather
        // than opening each WebSocket inline (which fires every handshake
        // in the same tick — a thundering herd of N simultaneous connects
        // from one IP at fleet scale), schedule each connect on the loop
        // with an incremental stagger so the fleet ramps online
        // gradually. A single steady-state respawn gets index 0 → zero
        // delay. The $spawning guard stops a later discovery tick from
        // double-scheduling an account whose staggered connect hasn't
        // landed in $accountClients yet.
        $index = 0;
        foreach ($eligibleAccounts as $account) {
            if (isset($this->accountClients[$account->id]) || isset($this->spawning[$account->id])) {
                continue;
            }

            $this->spawning[$account->id] = true;
            $delay = $this->connectStaggerDelay($index);
            $index++;

            $this->loop->addTimer($delay, function () use ($account) {
                try {
                    $this->initAccount($account);
                } catch (Throwable $exception) {
                    Log::channel('user-data')->error('[USER-DATA] init failed', [
                        'account_id' => $account->id,
                        'account_name' => $account->name,
                        'error' => $exception->getMessage(),
                    ]);

                    $this->notify(
                        'binance_user_data_account_init_failed',
                        [
                            'account_id' => $account->id,
                            'account_name' => $account->name,
                            'error' => $exception->getMessage(),
                        ],
                        cacheKey: ['account_id' => (string) $account->id]
                    );
                } finally {
                    unset($this->spawning[$account->id]);
                }
            });
        }
    }

    /**
     * Per-account boot:
     *   1. Mint a fresh listenKey (Binance REST).
     *   2. Persist it into binance_listen_keys (one row per account).
     *   3. Open the authenticated WS via subscribeToUserStreamAsync.
     *      "Async" means: register against the event loop and return —
     *      blocking on Loop::run() inside a per-account init would
     *      prevent us from initialising any subsequent account.
     */
    private function initAccount(Account $account): void
    {
        $rest = new BinanceApi(new ApiCredentials($account->all_credentials));
        $listenKey = $rest->createListenKey();

        BinanceListenKey::updateOrCreate(
            ['account_id' => $account->id],
            [
                'listen_key' => $listenKey,
                'last_created_at' => now(),
                'last_keep_alive_at' => now(),
                'last_keep_alive_status' => 'success',
                'failure_count' => 0,
            ]
        );

        $client = new BinanceApiClient([
            'base_url' => 'wss://fstream.binance.com',
            'idle_timeout_seconds' => self::IDLE_TIMEOUT_SECONDS,
        ]);

        $client->subscribeToUserStreamAsync($listenKey, [
            'message' => function ($conn, $raw) use ($account) {
                $this->onMessage($account, (string) $raw);
            },
            'close' => function () use ($account) {
                Log::channel('user-data')->warning('[USER-DATA] connection closed', [
                    'account_id' => $account->id,
                ]);
                // Drop the slot so discoverNewAccounts() (which skips
                // accounts already in $accountClients) can re-spawn
                // this account on the next discovery tick. Pre-fix,
                // close just logged — the slot stayed populated, the
                // socket was dead, and the daemon never re-init'd
                // until a listen-key rotation, daemon restart, or
                // manual intervention.
                $this->closeAndUnsetSlot($account->id);
            },
            'error' => function ($conn, Throwable $exception) use ($account) {
                Log::channel('user-data')->error('[USER-DATA] connection error', [
                    'account_id' => $account->id,
                    'error' => $exception->getMessage(),
                ]);
                // Same rationale as the close handler: clear the slot
                // so the next discovery tick can re-spawn instead of
                // assuming a healthy connection that's actually dead.
                $this->closeAndUnsetSlot($account->id);
            },
        ]);

        $this->accountClients[$account->id] = [
            'client' => $client,
            'listen_key' => $listenKey,
            'last_frame_persisted_at' => 0.0,
        ];

        // Connect success is LOG-ONLY. A per-account "connected"
        // notification here fired on every (re)connect — boot, deploy,
        // rotation, expiry, transient drop — turning a daemon restart at
        // 100 accounts into a 100-message storm. The once-per-boot
        // summary (binance_user_data_daemon_online) covers "the fleet is
        // online"; routine per-account reconnects are self-healing and
        // don't warrant an alert. Only FAILURE to connect (init_failed)
        // and lifecycle events (expiry, reap, memory restart) still page.
        Log::channel('user-data')->info('[USER-DATA] account initialised', [
            'account_id' => $account->id,
            'account_name' => $account->name,
        ]);
    }

    /**
     * Frame router. Intentionally minimal — heavy work runs in the
     * Step worker, not the event loop.
     *
     *   1. Decode JSON; drop malformed frames with a log line.
     *   2. Daemon-level branch: listenKeyExpired forces a re-init for
     *      that account, since dispatching the event into a Step
     *      would not recover the dead WS.
     *   3. Dispatch a Step on the dedicated user-data queue carrying
     *      the raw payload + account context. The worker handles
     *      everything else.
     */
    private function onMessage(Account $account, string $raw): void
    {
        $payload = json_decode($raw, true);

        if (! is_array($payload)) {
            Log::channel('user-data')->warning('[USER-DATA] malformed JSON frame', [
                'account_id' => $account->id,
                'bytes' => mb_strlen($raw),
            ]);

            return;
        }

        // Throttled heartbeat write to binance_listen_keys.last_frame_at
        // so external monitors can detect silent stream death without
        // grepping logs. The throttle is per-account in-memory; the
        // daemon process is single-threaded so no race.
        $this->maybePersistFrameHeartbeat($account);

        if (($payload['e'] ?? null) === 'listenKeyExpired') {
            $this->handleListenKeyExpired($account);
        }

        // Step::create can throw on transient DB unavailability,
        // observer-thrown exceptions, validation errors, etc. Without
        // a guard the throw propagates up through ReactPHP's message
        // handler; ReactPHP has no global error boundary inside the
        // event loop, so an unhandled exception either silently breaks
        // dispatch for that frame OR (worst case) wedges the loop.
        // Either way the trade-relevant frame would be lost forever.
        //
        // Strategy: catch + persist the raw frame to disk so an
        // operator can replay it after the underlying issue is fixed.
        // Disk write is the simplest dead-letter store available — no
        // dependencies on the DB or queue that just failed.
        //
        // Tight per-event prefix push: this daemon is a long-running
        // ReactPHP loop, so wrapping the entire process lifetime would
        // leak the prefix across non-trading work that might also run
        // here in future. Push only for the duration of the Step::create
        // and let the closure pop on return / throw.
        try {
            Steps::usingPrefix('trading', function () use ($account, $payload): void {
                Step::create([
                    'class' => ProcessUserDataEventJob::class,
                    'queue' => self::PROCESS_QUEUE,
                    'arguments' => [
                        'accountId' => $account->id,
                        'apiSystemId' => (int) $account->api_system_id,
                        'apiSystemCanonical' => 'binance',
                        'payload' => $payload,
                    ],
                ]);
            });
        } catch (Throwable $exception) {
            $this->stashFrameToDisk($account, $payload, $exception);
        }
    }

    /**
     * Dead-letter dump for frames that failed to dispatch as Steps.
     * Writes one JSONL line per frame to
     * `storage/logs/user-data-deadletter-YYYY-MM-DD.log` so an operator
     * can replay them after the underlying failure is resolved. Uses
     * `file_put_contents` with FILE_APPEND + LOCK_EX so concurrent
     * frames from the same daemon serialise on the file lock.
     */
    private function stashFrameToDisk(Account $account, array $payload, Throwable $exception): void
    {
        $line = json_encode([
            'ts' => now()->toIso8601String(),
            'account_id' => $account->id,
            'account_name' => $account->name,
            'error' => $exception->getMessage(),
            'payload' => $payload,
        ]).PHP_EOL;

        $path = storage_path('logs/user-data-deadletter-'.now()->format('Y-m-d').'.log');

        // Suppress filesystem errors — the daemon must keep running
        // even if the disk is full, the path is read-only, etc. Loss
        // of the dead-letter line is preferable to loss of the daemon.
        // BUT capture the return value: when the write actually fails
        // (disk full, permission, no space, etc.) the dead-letter path
        // has lost the event silently. Pre-fix the daemon logged
        // "stashed to disk" regardless of whether the write succeeded.
        // Now we know which case happened and surface a louder signal
        // for the lossy case so checkUserDataDeadLetters can detect it.
        $bytesWritten = @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);

        if ($bytesWritten === false || $bytesWritten === 0) {
            Log::channel('user-data')->critical('[USER-DATA] DEAD-LETTER WRITE FAILED — frame LOST', [
                'account_id' => $account->id,
                'event_type' => $payload['e'] ?? 'unknown',
                'deadletter_file' => basename($path),
                'underlying_error' => $exception->getMessage(),
                'reason' => 'file_put_contents returned false/0 — disk full / permission / read-only filesystem',
            ]);

            return;
        }

        Log::channel('user-data')->error('[USER-DATA] frame dispatch failed — stashed to disk', [
            'account_id' => $account->id,
            'event_type' => $payload['e'] ?? 'unknown',
            'deadletter_file' => basename($path),
            'error' => $exception->getMessage(),
        ]);
    }

    private function handleListenKeyExpired(Account $account): void
    {
        Log::channel('user-data')->warning('[USER-DATA] listenKeyExpired received — re-initialising account', [
            'account_id' => $account->id,
        ]);

        $this->notify(
            'binance_user_data_listen_key_expired',
            ['account_id' => $account->id, 'account_name' => $account->name],
            cacheKey: ['account_id' => (string) $account->id]
        );

        $this->closeAndUnsetSlot($account->id);

        BinanceListenKey::where('account_id', $account->id)->delete();

        try {
            $this->initAccount($account);
        } catch (Throwable $exception) {
            Log::channel('user-data')->error('[USER-DATA] re-init after expiry failed', [
                'account_id' => $account->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Tear down the WS slot for an account that's no longer eligible
     * (deactivated, deleted, key removed). Called by discoverNewAccounts
     * on every sweep where the slot's account doesn't appear in the
     * eligible set.
     *
     * Steps:
     *   1. Send a final close on the underlying Pawl connection so
     *      Binance gets a clean disconnect (no zombie session counted
     *      against the account's stream limit).
     *   2. Remove the slot from $accountClients so the watchdog timers
     *      stop checking it and a future re-activation goes through
     *      the spawn path again.
     *   3. Delete the binance_listen_keys row so the keepalive cron
     *      stops refreshing a key for an account that should not be
     *      streaming. This also makes the in-memory state and the DB
     *      converge — `binance_listen_keys` becomes the canonical
     *      source of truth for "this account is being streamed."
     *
     * Notification fires once per reap (cache_key per account_id) so
     * a one-off deactivation alerts ops; oscillating activation flips
     * fire the alert on every transition.
     */
    private function reapAccount(int $accountId): void
    {
        $slot = $this->accountClients[$accountId] ?? null;
        if ($slot === null) {
            return;
        }

        Log::channel('user-data')->warning('[USER-DATA] reaping account — no longer eligible', [
            'account_id' => $accountId,
            'reason' => 'is_active=false / deleted / api_key=null',
        ]);

        // Closing the Pawl connection is best-effort. The WS client
        // may already be in a half-disconnected state; calling close()
        // on a dead connection is a no-op.
        try {
            $slot['client']->close();
        } catch (Throwable $exception) {
            Log::channel('user-data')->warning('[USER-DATA] reap close threw', [
                'account_id' => $accountId,
                'error' => $exception->getMessage(),
            ]);
        }

        unset($this->accountClients[$accountId]);

        try {
            BinanceListenKey::where('account_id', $accountId)->delete();
        } catch (Throwable $exception) {
            Log::channel('user-data')->warning('[USER-DATA] reap listen_key delete threw', [
                'account_id' => $accountId,
                'error' => $exception->getMessage(),
            ]);
        }

        $this->notify(
            'binance_user_data_account_reaped',
            ['account_id' => $accountId],
            cacheKey: ['account_id' => (string) $accountId]
        );
    }

    /**
     * Compare each open slot's listen_key against the DB row written
     * by the keepalive cron. If they diverge, Binance has rotated the
     * key — the cron updated the DB but the daemon's WS is still
     * subscribed to the old URL. Re-init the account to pick up the
     * new key before the old one auto-expires (60min) and the WS
     * dies silently.
     *
     * Rotation is rare in practice (Binance usually extends in place)
     * but if we miss it the daemon enters an unrecoverable state for
     * that account.
     */
    private function checkListenKeyRotations(): void
    {
        if ($this->accountClients === []) {
            return;
        }

        $rows = BinanceListenKey::query()
            ->whereIn('account_id', array_keys($this->accountClients))
            ->get(['account_id', 'listen_key']);

        foreach ($rows as $row) {
            $slot = $this->accountClients[$row->account_id] ?? null;
            if ($slot === null) {
                continue;
            }

            if ($row->listen_key === $slot['listen_key']) {
                continue;
            }

            Log::channel('user-data')->warning('[USER-DATA] listen_key rotation detected — re-initialising account', [
                'account_id' => $row->account_id,
                'old_key_suffix' => mb_substr($slot['listen_key'], -8),
                'new_key_suffix' => mb_substr($row->listen_key, -8),
            ]);

            $account = Account::find($row->account_id);
            if ($account === null) {
                continue;
            }

            $this->closeAndUnsetSlot($row->account_id);

            try {
                $this->initAccount($account);
            } catch (Throwable $exception) {
                Log::channel('user-data')->error('[USER-DATA] re-init after rotation failed', [
                    'account_id' => $account->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * Throttled per-account write to binance_listen_keys.last_frame_at.
     * The daemon's in-memory $lastFrameAt on each WS client tracks this
     * already; persisting it gives external monitors (admin UI,
     * healthchecks) a view of which accounts are silently dead while
     * the supervisor still reports the daemon process as RUNNING.
     */
    private function maybePersistFrameHeartbeat(Account $account): void
    {
        $slot = $this->accountClients[$account->id] ?? null;
        if ($slot === null) {
            return;
        }

        $now = microtime(true);
        if ($now - $slot['last_frame_persisted_at'] < self::FRAME_HEARTBEAT_THROTTLE_SECONDS) {
            return;
        }

        $this->accountClients[$account->id]['last_frame_persisted_at'] = $now;

        try {
            BinanceListenKey::where('account_id', $account->id)->update([
                'last_frame_at' => now(),
            ]);
        } catch (Throwable $exception) {
            // Heartbeat write must never block the event loop; failure
            // here just means ops monitoring sees a stale timestamp.
            Log::channel('user-data')->warning('[USER-DATA] heartbeat persist failed', [
                'account_id' => $account->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Touch a flag file so an external monitor (Pingdom-style HTTP
     * check, healthchecks.io, supervisord eventlistener, etc.) can
     * detect a wedged event loop by looking at the file's mtime. The
     * supervisor's RUNNING status only tells us the process exists —
     * it doesn't catch an event loop that hangs inside a long
     * synchronous operation.
     *
     * Path is intentionally outside `storage/logs` so log rotation
     * doesn't confuse the monitor. Created lazily; failures are
     * suppressed so a read-only filesystem can't crash the daemon.
     */
    private function touchHeartbeatFlag(): void
    {
        $path = storage_path('app/user-data-daemon.heartbeat');
        @touch($path);
    }

    /**
     * Soft restart on memory pressure. Long-running PHP daemons can
     * accumulate references through ReactPHP closures and Eloquent's
     * model graph; gc_collect_cycles helps but doesn't fully recover.
     * When resident memory crosses MEMORY_LIMIT_BYTES we exit cleanly
     * and let the supervisor respawn a fresh process.
     *
     * The exit path: stop the loop, return from handle(), supervisor's
     * autorestart=true respawns. New process picks up the existing
     * binance_listen_keys rows and reconnects within seconds — frames
     * during the restart window are missed but the keepalive cron
     * keeps the keys alive in the DB so no re-issue is needed.
     */
    private function checkMemoryPressure(): void
    {
        $bytes = memory_get_usage(true);
        $limit = $this->memoryLimitBytes();

        if ($bytes < $limit) {
            // Periodic gc_collect helps reclaim cycle-collected
            // closures before we hit the hard limit.
            if ($bytes > $limit / 2) {
                gc_collect_cycles();
            }

            return;
        }

        Log::channel('user-data')->warning('[USER-DATA] memory limit exceeded — exiting for supervisor restart', [
            'memory_bytes' => $bytes,
            'limit_bytes' => $limit,
            'account_count' => count($this->accountClients),
            'pid' => getmypid(),
        ]);

        $this->notify(
            'binance_user_data_memory_restart',
            [
                'memory_bytes' => $bytes,
                'limit_bytes' => $limit,
                'pid' => getmypid(),
            ],
        );

        Loop::get()->stop();
    }

    /**
     * @param  array<string, mixed>  $referenceData
     * @param  array<string, string>  $cacheKey
     */
    private function notify(string $canonical, array $referenceData, array $cacheKey = []): void
    {
        try {
            NotificationService::send(
                user: Kraite::admin(),
                canonical: $canonical,
                referenceData: $referenceData,
                cacheKeys: $cacheKey,
            );
        } catch (Throwable $exception) {
            Log::channel('user-data')->warning('[USER-DATA] alert dispatch failed', [
                'canonical' => $canonical,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
