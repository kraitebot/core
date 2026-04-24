<?php

declare(strict_types=1);

namespace Kraite\Core\Abstracts;

use Illuminate\Support\Facades\Log;
use Ratchet\Client\Connector;
use Ratchet\Client\WebSocket;
use Ratchet\RFC6455\Messaging\Frame;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;

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
    protected string $baseURL;

    protected ?Connector $wsConnector;

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

    protected float $lastFrameAt = 0.0;

    protected int $framesReceived = 0;

    protected float $connectedAt = 0.0;

    private bool $watchdogStarted = false;

    public function __construct(array $args = [])
    {
        $this->baseURL = $args['baseURL'] ?? '';
        $this->loop = Loop::get();
        $this->wsConnector = new Connector($this->loop);
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

    protected function handleCallback(string $url, array $callback): void
    {
        $this->startWatchdogOnce($url);

        $this->createWSConnection($url)->then(
            function (WebSocket $conn) use ($callback, $url) {
                $this->wsConnection = $conn;
                $this->reconnectAttempt = 0;
                $this->connectedAt = microtime(true);
                $this->lastFrameAt = microtime(true);
                $this->framesReceived = 0;

                Log::channel('jobs')->info('[WEBSOCKET] Connected', ['url' => $url]);

                // Auto-pong on every incoming ping + a periodic pong every
                // 15 minutes so idle connections don't get dropped by the
                // exchange's stale-client sweep. Both handlers bump
                // lastFrameAt so pings-only traffic still counts as "alive"
                // against the idle-timeout watchdog.
                $conn->on('ping', function () use ($conn) {
                    $this->lastFrameAt = microtime(true);
                    $conn->send(new Frame('', true, Frame::OP_PONG));
                });

                $conn->on('message', function ($msg) use ($conn, $callback) {
                    $this->lastFrameAt = microtime(true);
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

                $this->reconnect($url, $callback);
            }
        );

        $this->loop->run();
    }

    private function createWSConnection(string $url)
    {
        return ($this->wsConnector)($url);
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

        $this->loop->addTimer($delay, function () use ($url, $callback) {
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

        // Idle-frame detector. When lastFrameAt gets stale beyond the
        // threshold, force-close the connection; the close handler then
        // fires reconnect(). This is the defence against the "TCP is
        // open but the server stopped talking" zombie scenario that
        // froze the Binance daemon on 2026-04-24.
        $this->loop->addPeriodicTimer($this->watchdogCheckIntervalSeconds, function () use ($url) {
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
