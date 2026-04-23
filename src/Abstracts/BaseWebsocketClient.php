<?php

declare(strict_types=1);

namespace Kraite\Core\Abstracts;

use Exception;
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
 * - Exponential-backoff reconnect (2^attempt seconds, max 5 attempts) so
 *   a transient disconnect heals itself; after the cap we bail loudly.
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

    protected int $maxReconnectAttempts = 5;

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
        $this->createWSConnection($url)->then(
            function (WebSocket $conn) use ($callback, $url) {
                $this->wsConnection = $conn;
                $this->reconnectAttempt = 0;

                // Auto-pong on every incoming ping + a periodic pong every
                // 15 minutes so idle connections don't get dropped by the
                // exchange's "stale client" sweep.
                $conn->on('ping', function () use ($conn) {
                    $conn->send(new Frame('', true, Frame::OP_PONG));
                });

                $this->loop->addPeriodicTimer(900, function () use ($conn) {
                    $conn->send(new Frame('', true, Frame::OP_PONG));
                });

                $conn->on('message', function ($msg) use ($conn, $callback) {
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

    private function reconnect(string $url, array $callback): void
    {
        if ($this->reconnectAttempt >= $this->maxReconnectAttempts) {
            Log::channel('jobs')->error(
                '[WEBSOCKET] Max reconnect attempts reached — giving up',
                ['url' => $url, 'attempts' => $this->maxReconnectAttempts]
            );

            if (isset($callback['error']) && is_callable($callback['error'])) {
                $callback['error'](null, new Exception('Max reconnect attempts reached. Connection closed.'));
            }

            return;
        }

        $delay = 2 ** $this->reconnectAttempt;

        if ($this->reconnectAttempt > 0) {
            Log::channel('jobs')->warning(
                '[WEBSOCKET] Reconnecting',
                ['url' => $url, 'delay_seconds' => $delay, 'attempt' => $this->reconnectAttempt]
            );
        }

        $this->loop->addTimer($delay, function () use ($url, $callback) {
            $this->reconnectAttempt++;
            $this->handleCallback($url, $callback);
        });
    }
}
