<?php

declare(strict_types=1);

namespace Kraite\Core\Abstracts;

use Illuminate\Support\Facades\Log;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Support\FreezeMode;
use Kraite\Core\Support\NotificationService;
use Ratchet\Client\Connector;
use Ratchet\Client\WebSocket;
use Ratchet\RFC6455\Messaging\Frame;
use React\Dns\Resolver\Factory as DnsResolverFactory;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Socket\Connector as SocketConnector;
use Throwable;

/**
 * BaseWebsocketClient
 *
 * Abstract WebSocket client using Ratchet/Pawl + ReactPHP's event loop.
 *
 * Responsibilities:
 * - Owns the event-loop lifecycle for a long-running daemon process.
 * - Dispatches user-supplied callbacks for message / ping / pong / close /
 *   error so each stream can plug in its own decoding.
 * - Implements auto-ping / auto-pong so the exchange's keepalive protocol
 *   is respected without the caller having to think about it.
 * - Exponential-backoff reconnect (capped at $maxReconnectBackoffSeconds)
 *   with NO attempt cap — a supervised daemon must always be trying to
 *   recover, even through multi-hour outages.
 * - Idle-read watchdog that force-closes the socket when no frame has
 *   been received for $idleTimeoutSeconds. Covers the zombie case where
 *   the TCP connection stays ESTABLISHED but the server stops sending
 *   frames without ever firing a close event — exactly the failure mode
 *   that froze the Binance daemon for 14h on 2026-04-24.
 * - Periodic heartbeat log so operators can eyeball the log and see the
 *   daemon is actually pumping frames, not just "RUNNING" in supervisor.
 *
 * Subclasses provide the base URL and any stream-specific construction —
 * the websocket-level mechanics live here.
 */
abstract class BaseWebsocketClient
{
    /**
     * Public DNS used by the Pawl connector. We deliberately bypass the
     * host's `/etc/resolv.conf` (systemd-resolved stub at 127.0.0.53) because
     * a single network blip can wedge ReactPHP's UDP socket bound to the
     * stub and every subsequent reconnect logs "Unable to connect to DNS
     * server udp://127.0.0.53:53" forever — the exact failure mode that
     * froze this daemon for ~3 days starting 2026-04-27. Cloudflare's
     * 1.1.1.1 keeps the resolver path independent of host plumbing.
     */
    protected const DNS_NAMESERVER = '1.1.1.1';

    protected string $baseURL;

    protected ?WebSocket $wsConnection = null;

    protected LoopInterface $loop;

    protected int $reconnectAttempt = 0;

    /**
     * Force a reconnect when no frame has arrived in this many seconds.
     * Binance's !markPrice@arr@1s delivers every second when healthy, so
     * anything beyond ~30s of radio silence is a dead socket regardless
     * of what the OS reports about the TCP connection.
     */
    protected int $idleTimeoutSeconds = 30;

    /**
     * Emit a heartbeat log every N seconds so an empty daemon log =
     * "the daemon died", not "the daemon is silently processing".
     */
    protected int $heartbeatLogSeconds = 60;

    /**
     * Cadence of the internal idle-timeout sweep.
     */
    protected int $watchdogCheckIntervalSeconds = 10;

    /**
     * Cap for the exponential-backoff reconnect delay. We use 2^attempt
     * seconds without a cap early on, and clamp to this value so a long
     * outage converges to one reconnect attempt per $maxReconnectBackoffSeconds
     * instead of stretching into multi-hour gaps.
     */
    protected int $maxReconnectBackoffSeconds = 30;

    /**
     * Number of consecutive reconnect attempts (with no successful
     * frame in between) that triggers a "reconnect storm" Pushover
     * alert. We retry forever by design, but the absence of escalation
     * hides a slow-degrade scenario where one stream flaps connect →
     * close → reconnect every 30s for hours without a human noticing.
     *
     * `reconnectAttempt` resets to 0 on a successful frame in
     * registerCallbacks, so this threshold is "10 retries with no
     * forward progress", not "10 retries cumulative".
     */
    protected int $reconnectStormThreshold = 10;

    protected float $lastFrameAt = 0.0;

    /**
     * Sustained no-data self-exit threshold, in seconds. When set, the
     * watchdog stops the event loop (for supervisor respawn) once no
     * DATA frame has arrived for this long — regardless of how many
     * reconnects happened in between.
     *
     * `null` (default) disables the behaviour so sparse-data streams
     * (user-data: silent for hours on a quiet account) never self-exit.
     * Strict-data streams (mark-price: 1Hz expected) opt in.
     *
     * Why a fresh PROCESS and not just another reconnect: the 2026-07-02
     * incident showed a transient network blip can wedge ReactPHP's DNS
     * resolver on the shared event loop. The "fresh connector per
     * attempt" mitigation does NOT clear a loop-level UDP wedge — 46,088
     * reconnects failed over ~4h with zero frames, and mark prices froze.
     * Only a process restart cleared it. This threshold makes that
     * recovery automatic instead of waiting for a human.
     */
    protected ?int $noDataSelfExitSeconds = null;

    /**
     * Monotonic clock (microtime as float) of the last real DATA frame.
     *
     * Distinct from $lastFrameAt: this advances ONLY on an actual message
     * frame and is NEVER reset by a (re)connect or a protocol ping. That
     * makes it age correctly through BOTH failure phases seen on
     * 2026-07-02 — the never-connect DNS-wedge phase (no connect resets
     * it) AND the connect-then-silent idle-flap phase (a reconnect's
     * successful open would otherwise reset $lastFrameAt and mask the
     * silence). It is the signal the no-data self-exit reads.
     */
    protected float $lastDataFrameAt = 0.0;

    /**
     * Whether protocol-level pings count toward the idle-watchdog's
     * "still alive" signal.
     *
     * Default `true` preserves the legacy contract for streams whose
     * data cadence is sparse (user-data: order events only, can be
     * hours of silence on a quiet account). Strict-data streams
     * (mark-price: 1Hz expected) override to `false` so a silent
     * data feed with intact TCP-keepalive trips the watchdog.
     */
    protected bool $pingsCountAsAlive = true;

    protected int $framesReceived = 0;

    protected float $connectedAt = 0.0;

    /**
     * Whether we've already raised the storm alert for the current
     * uninterrupted streak. Cleared whenever the connection succeeds
     * and a frame arrives.
     */
    private bool $reconnectStormAlertRaised = false;

    private bool $watchdogStarted = false;

    /**
     * Reconnect cancellation flag.
     *
     * Default `true`: the close → reconnect chain is the project's
     * always-on contract for supervised daemons (network blips, server
     * restarts, etc. should never kill the stream).
     *
     * Set to `false` by the public `close()` method so an INTENTIONAL
     * teardown (account reap, listen-key rotation, listen-key expiry)
     * does NOT schedule a reconnect of the now-stale URL. Without this
     * flag, the Pawl close-event handler unconditionally called
     * `reconnect()` after every close — which meant the daemon's
     * teardown path kept the old URL alive on a backoff timer and
     * eventually re-opened a ghost connection for an account that
     * had been reaped or had a fresh listenKey minted.
     *
     * The flag is checked BOTH in the close-event handler (to skip
     * scheduling a fresh reconnect timer) AND inside the deferred
     * timer callback (to swallow a timer that was already scheduled
     * before close() ran).
     */
    private bool $shouldReconnect = true;

    public function __construct(array $args = [])
    {
        $this->baseURL = $args['baseURL'] ?? '';
        $this->loop = Loop::get();

        // Per-instance idle-timeout override. The default (30s) is tuned
        // for high-frequency streams like Binance's `!markPrice@arr@1s`
        // where any silence past 30s is a dead socket. User-data streams
        // are silent until events fire (Binance pings every ~3min); they
        // need a much longer threshold so the watchdog only reconnects
        // on genuine zombies, not on natural quiet periods.
        if (isset($args['idleTimeoutSeconds']) && $args['idleTimeoutSeconds'] > 0) {
            $this->idleTimeoutSeconds = (int) $args['idleTimeoutSeconds'];
        }

        // Opt-in sustained-no-data self-exit. Only strict-data streams
        // (mark-price) pass this; sparse-data streams leave it null so a
        // legitimately quiet feed never self-exits.
        if (isset($args['noDataSelfExitSeconds']) && $args['noDataSelfExitSeconds'] > 0) {
            $this->noDataSelfExitSeconds = (int) $args['noDataSelfExitSeconds'];
        }
    }

    final public function ping(): void
    {
        if ($this->wsConnection) {
            $this->wsConnection->send(new Frame('', true, Frame::OP_PING));

            return;
        }

        Log::channel('jobs')->warning(
            '[WEBSOCKET] Ping attempted but connection not established',
            ['base_url' => $this->baseURL]
        );
    }

    /**
     * Public close hook for callers that need to shut a stream down
     * intentionally (account deactivation, daemon-level reaper). The
     * existing reconnect path uses the same primitive on the
     * connection's `close` callback — we just expose it so external
     * code doesn't have to reach into the protected member.
     *
     * `wsConnection` is nulled FIRST so any in-flight callback can't
     * race with the close handler rehydrating it; this mirrors the
     * idle-watchdog teardown logic.
     */
    final public function close(): void
    {
        // Mark this teardown as intentional BEFORE the underlying close
        // runs. Pawl's close-event handler will fire $conn->close()
        // synchronously and (without this flag) schedule a reconnect
        // of the now-stale URL. Setting the flag first guarantees the
        // close-event handler skips the reconnect.
        $this->shouldReconnect = false;

        if ($this->wsConnection === null) {
            return;
        }

        $conn = $this->wsConnection;
        $this->wsConnection = null;
        $conn->close();
    }

    /**
     * Non-blocking variant of handleCallback.
     *
     * Registers the connection + handlers against the singleton event
     * loop and returns immediately. The caller is responsible for
     * driving the loop with `Loop::get()->run()` after all
     * subscriptions have been registered. This is the entry point used
     * by daemons that host multiple WebSockets in one process — the
     * blocking `handleCallback` would only ever start the first one.
     *
     * Behaviour is otherwise identical to `handleCallback`.
     */
    final public function handleCallbackAsync(string $url, array $callback): void
    {
        $this->registerCallbacks($url, $callback);
    }

    /**
     * Whether the stream has gone without a real data frame long enough
     * to warrant a self-exit for supervisor respawn.
     *
     * Guards: disabled unless a threshold is configured, and never trips
     * before the watchdog has primed $lastDataFrameAt at daemon start
     * (a zero clock must not read as "infinitely stale").
     */
    protected function shouldSelfExitForNoData(float $now): bool
    {
        if ($this->noDataSelfExitSeconds === null) {
            return false;
        }

        if ($this->lastDataFrameAt <= 0.0) {
            return false;
        }

        return ($now - $this->lastDataFrameAt) > $this->noDataSelfExitSeconds;
    }

    protected function handleCallback(string $url, array $callback): void
    {
        $this->registerCallbacks($url, $callback);

        $this->loop->run();
    }

    private function registerCallbacks(string $url, array $callback): void
    {
        if (FreezeMode::isActive()) {
            $this->shouldReconnect = false;
            $this->loop->stop();

            return;
        }

        $this->startWatchdogOnce($url);

        $this->createWSConnection($url)->then(
            function (WebSocket $conn) use ($callback, $url) {
                $this->wsConnection = $conn;
                $this->reconnectAttempt = 0;
                $this->reconnectStormAlertRaised = false;
                $this->connectedAt = microtime(true);
                $this->lastFrameAt = microtime(true);
                $this->framesReceived = 0;

                Log::channel('jobs')->info('[WEBSOCKET] Connected', ['url' => $url]);

                // Auto-pong on every incoming ping + a periodic pong every
                // 15 minutes so idle connections don't get dropped by the
                // exchange's stale-client sweep.
                //
                // Two semantically-different streams use this client:
                //
                //   - Mark-price stream: data is expected ~1Hz, so the
                //     idle watchdog must NOT count protocol-level pings
                //     as "alive" — otherwise a silent data feed with
                //     intact TCP-keepalive masks the death and the
                //     watchdog never trips.
                //   - User-data stream: legitimately silent for long
                //     stretches on quiet accounts (only emits on order
                //     events), so server pings ARE the proof that TCP
                //     is alive and the watchdog should accept them.
                //
                // The `$pingsCountAsAlive` flag (default true → preserve
                // legacy contract) lets each subclass / instance opt
                // into stricter "data-only" liveness. Mark-price daemon
                // sets it to false. 2026-05-03 incidents validated both
                // failure modes: mark-price stream went silent + watchdog
                // false-passed; user-data stream legitimately silent +
                // watchdog false-failed.
                $conn->on('ping', function () use ($conn) {
                    if ($this->pingsCountAsAlive) {
                        $this->lastFrameAt = microtime(true);
                    }
                    $conn->send(new Frame('', true, Frame::OP_PONG));
                });

                $conn->on('message', function ($msg) use ($conn, $callback) {
                    $this->lastFrameAt = microtime(true);
                    // Real data frame — advance the no-data clock. This is
                    // the ONLY place lastDataFrameAt moves (never on connect
                    // or ping) so it ages correctly through a reconnect flap.
                    $this->lastDataFrameAt = microtime(true);
                    $this->framesReceived++;

                    $payload = (string) $msg;

                    if (is_callable($callback['message'] ?? null)) {
                        $callback['message']($conn, $payload);
                    }

                    // Some exchanges embed an application-level ping inside
                    // the JSON payload (e.g. `{"ping": 1234}`). Echo it back
                    // as `{"pong": 1234}` so keepalive works at both layers.
                    $decoded = json_decode($payload, true);
                    if (is_array($decoded) && isset($decoded['ping']) && is_callable($callback['pong'] ?? null)) {
                        $conn->send(json_encode(['pong' => $decoded['ping']]));
                    }
                });

                if (isset($callback['ping']) && is_callable($callback['ping'])) {
                    $conn->on('ping', fn ($msg) => $callback['ping']($conn, $msg));
                }

                $conn->on('close', function () use ($conn, $url, $callback) {
                    Log::channel('jobs')->warning(
                        '[WEBSOCKET] Connection closed',
                        [
                            'url' => $url,
                            'uptime_seconds' => round(microtime(true) - $this->connectedAt, 2),
                            'frames_received' => $this->framesReceived,
                        ]
                    );

                    $this->wsConnection = null;

                    if (isset($callback['close']) && is_callable($callback['close'])) {
                        $callback['close']($conn);
                    }

                    // Skip the reconnect chain on an intentional close
                    // (account reap, listen-key rotation/expiry). Without
                    // this guard, the daemon's teardown path would
                    // schedule a reconnect of the stale URL and re-open
                    // a ghost connection for an account that had been
                    // reaped or had a fresh listenKey minted.
                    if (! $this->shouldReconnect) {
                        Log::channel('jobs')->info(
                            '[WEBSOCKET] Reconnect skipped — intentional close',
                            ['url' => $url]
                        );

                        return;
                    }

                    $this->reconnect($url, $callback);
                });
            },
            function ($e) use ($url, $callback) {
                Log::channel('jobs')->error(
                    '[WEBSOCKET] Could not connect',
                    ['url' => $url, 'error' => $e->getMessage()]
                );

                if (isset($callback['error']) && is_callable($callback['error'])) {
                    $callback['error'](null, $e);
                }

                // Same intentional-close guard as the close-event handler:
                // if close() ran while a connect was in flight, the failure
                // path here must NOT chain into a reconnect of the stale
                // URL.
                if (! $this->shouldReconnect) {
                    Log::channel('jobs')->info(
                        '[WEBSOCKET] Reconnect skipped — intentional close (during connect failure)',
                        ['url' => $url]
                    );

                    return;
                }

                $this->reconnect($url, $callback);
            }
        );
    }

    /**
     * Build a fresh Pawl Connector for every connect attempt.
     *
     * Each Pawl `Connector` carries a ReactPHP socket connector + DNS
     * resolver bound to a UDP socket at construction time. If that UDP
     * socket ever wedges (kernel sweep during a transient outage,
     * conntrack expiry, etc.) the resolver fails forever for the lifetime
     * of the connector — even after the host's network recovers. Building
     * fresh on each reconnect means a wedged socket is replaced
     * automatically; one bad attempt costs one retry, not the whole
     * daemon.
     *
     * `happy_eyeballs` is disabled because Binance has no AAAA record and
     * the IPv6 path returns NODATA, which Pawl's race logic still waits
     * on briefly — pure overhead on every reconnect.
     */
    private function createWSConnection(string $url)
    {
        FreezeMode::assertExternalTrafficAllowed('WebSocket connection');

        $dnsResolver = (new DnsResolverFactory)->createCached(self::DNS_NAMESERVER, $this->loop);

        $socketConnector = new SocketConnector([
            'dns' => $dnsResolver,
            'happy_eyeballs' => false,
            'timeout' => 10,
        ], $this->loop);

        $connector = new Connector($this->loop, $socketConnector);

        return $connector($url);
    }

    /**
     * Unbounded reconnect with capped exponential backoff. Supervised
     * daemons should never "give up" — a long outage becomes a long series
     * of retries, not a silent death. The cap on backoff prevents the
     * delay from stretching past $maxReconnectBackoffSeconds.
     */
    private function reconnect(string $url, array $callback): void
    {
        $delay = min(2 ** min($this->reconnectAttempt, 10), $this->maxReconnectBackoffSeconds);

        Log::channel('jobs')->warning(
            '[WEBSOCKET] Reconnecting',
            ['url' => $url, 'delay_seconds' => $delay, 'attempt' => $this->reconnectAttempt + 1]
        );

        // Storm escalation: once we've burned through the threshold
        // without a successful frame, raise an admin alert ONCE per
        // streak so ops sees slow-degrade situations. The flag clears
        // when connection succeeds (registerCallbacks) so a recovery
        // followed by a fresh storm raises again.
        if (! $this->reconnectStormAlertRaised && $this->reconnectAttempt >= $this->reconnectStormThreshold) {
            $this->reconnectStormAlertRaised = true;
            try {
                NotificationService::send(
                    user: Kraite::admin(),
                    canonical: 'websocket_reconnect_storm',
                    referenceData: [
                        'url' => $url,
                        'attempts' => $this->reconnectAttempt,
                        'threshold' => $this->reconnectStormThreshold,
                    ],
                );
            } catch (Throwable $e) {
                Log::channel('jobs')->warning(
                    '[WEBSOCKET] storm alert dispatch failed',
                    ['error' => $e->getMessage()]
                );
            }
        }

        $this->loop->addTimer($delay, function () use ($url, $callback) {
            // Final cancellation check inside the deferred timer. This
            // catches the race where close() runs AFTER reconnect()
            // already scheduled the timer but BEFORE the timer fires.
            // Without this guard, the timer would re-open a connection
            // to the stale URL even though shouldReconnect was flipped
            // to false in the meantime.
            if (FreezeMode::isActive() || ! $this->shouldReconnect) {
                $this->shouldReconnect = false;
                $this->loop->stop();

                Log::channel('jobs')->info(
                    '[WEBSOCKET] Deferred reconnect cancelled — intentional close ran while timer was pending',
                    ['url' => $url]
                );

                return;
            }

            $this->reconnectAttempt++;
            $this->handleCallback($url, $callback);
        });
    }

    /**
     * Wire the idle watchdog + heartbeat log into the event loop on the
     * first handleCallback invocation. Both timers persist across
     * reconnects because they read $this->wsConnection dynamically each
     * tick, not a captured reference — so a reconnect that replaces the
     * connection just means the next tick operates on the new one.
     */
    private function startWatchdogOnce(string $url): void
    {
        if ($this->watchdogStarted) {
            return;
        }
        $this->watchdogStarted = true;

        // Prime the no-data clock at daemon start so the self-exit window
        // is measured from process launch, not from epoch. Without this a
        // slow first connect would look "infinitely stale" on the first
        // watchdog tick.
        $this->lastDataFrameAt = microtime(true);

        // Idle-frame detector. When lastFrameAt gets stale beyond the
        // threshold, force-close the connection; the close handler then
        // fires reconnect(). This is the defence against the "TCP is
        // open but the server stopped talking" zombie scenario that
        // froze the Binance daemon on 2026-04-24.
        $this->loop->addPeriodicTimer($this->watchdogCheckIntervalSeconds, function () use ($url) {
            if (FreezeMode::isActive()) {
                $this->close();
                $this->loop->stop();

                return;
            }

            // Sustained-no-data self-exit. Checked BEFORE the wsConnection
            // null-guard on purpose: the DNS-wedge failure mode
            // (2026-07-02) holds wsConnection null across thousands of
            // failed reconnects, so a guard-first check would never see
            // it. A fresh PROCESS is the only reliable way to clear a
            // loop-level ReactPHP DNS/UDP wedge — stop the loop and let
            // supervisor's autorestart respawn a clean process.
            if ($this->shouldSelfExitForNoData(microtime(true))) {
                Log::channel('jobs')->error(
                    '[WEBSOCKET] No data frame within self-exit window — stopping loop for supervisor respawn',
                    [
                        'url' => $url,
                        'seconds_since_last_data_frame' => (int) round(microtime(true) - $this->lastDataFrameAt),
                        'threshold' => $this->noDataSelfExitSeconds,
                    ]
                );

                $this->loop->stop();

                return;
            }

            if ($this->wsConnection === null) {
                return;
            }

            $idleFor = microtime(true) - $this->lastFrameAt;
            if ($idleFor <= $this->idleTimeoutSeconds) {
                return;
            }

            Log::channel('jobs')->warning(
                '[WEBSOCKET] Idle timeout — forcing reconnect',
                [
                    'url' => $url,
                    'idle_seconds' => round($idleFor, 2),
                    'threshold' => $this->idleTimeoutSeconds,
                    'frames_received' => $this->framesReceived,
                ]
            );

            try {
                NotificationService::send(
                    user: Kraite::admin(),
                    canonical: 'websocket_reconnect_triggered',
                    referenceData: [
                        'url' => $url,
                        'idle_seconds' => (int) round($idleFor),
                        'frames_received' => $this->framesReceived,
                        'uptime_seconds' => (int) round(microtime(true) - $this->connectedAt),
                    ],
                );
            } catch (Throwable $e) {
                Log::channel('jobs')->warning(
                    '[WEBSOCKET] reconnect notification failed',
                    ['error' => $e->getMessage()]
                );
            }

            // Null the reference first so any in-flight callback can't
            // race with the close event handler rehydrating it.
            $conn = $this->wsConnection;
            $this->wsConnection = null;
            $conn->close();
        });

        // Heartbeat log. Skipped while disconnected — the reconnect path
        // already logs state transitions, no need to echo silence.
        $this->loop->addPeriodicTimer($this->heartbeatLogSeconds, function () {
            if ($this->wsConnection === null) {
                return;
            }

            Log::channel('jobs')->info(
                '[WEBSOCKET] heartbeat',
                [
                    'connected_seconds' => round(microtime(true) - $this->connectedAt, 2),
                    'frames_received' => $this->framesReceived,
                    'seconds_since_last_frame' => round(microtime(true) - $this->lastFrameAt, 2),
                ]
            );
        });

        // Self-initiated pong every 15 minutes so a perfectly-silent
        // server-side sweep doesn't drop us.  Reads $this->wsConnection
        // dynamically so a reconnect-replaced connection just takes over.
        $this->loop->addPeriodicTimer(900, function () {
            if ($this->wsConnection === null) {
                return;
            }
            $this->wsConnection->send(new Frame('', true, Frame::OP_PONG));
        });
    }
}
