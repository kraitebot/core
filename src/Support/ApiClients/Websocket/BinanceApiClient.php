<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiClients\Websocket;

use Kraite\Core\Abstracts\BaseWebsocketClient;
use React\EventLoop\LoopInterface;

/**
 * BinanceApiClient (WebSocket)
 *
 * Binance-specific subscribe helper. Client-side rate limit caps outbound
 * subscribe requests at 10/sec (Binance limits inbound message rate per
 * connection). A periodic timer resets the counter every second — excess
 * subscribe calls within the same second are silently dropped rather than
 * queued, matching the martingalian reference.
 */
final class BinanceApiClient extends BaseWebsocketClient
{
    protected int $messageCount = 0;

    protected int $rateLimitInterval = 1;

    public function __construct(array $config)
    {
        parent::__construct([
            'baseURL' => $config['base_url'] ?? 'wss://fstream.binance.com',
            'wsConnector' => $config['ws_connector'] ?? null,
            'idleTimeoutSeconds' => $config['idle_timeout_seconds'] ?? null,
        ]);

        $this->loop->addPeriodicTimer($this->rateLimitInterval, function () {
            $this->messageCount = 0;
        });
    }

    public function subscribeToStream(string $streamName, array $callbacks): void
    {
        if ($this->messageCount >= 10) {
            return;
        }

        // Binance USDS-M futures WebSocket migration (2026-04-23): the
        // legacy root `/stream?streams=<name>` no longer pushes frames
        // for streams routed under /market or /private. Mark-price
        // (`!markPrice@arr@1s`) is a market-data stream → routes to
        // `/market/stream?streams=<name>`. Public-only streams
        // (e.g. `@depth`) can stay on `/public/stream?streams=<name>`.
        // Frames arrive in the combined-stream envelope:
        // `{"stream": "...", "data": [...]}` — subscribers unwrap `data`.
        //
        // See: developers.binance.com/docs/derivatives/USDS-margined-
        //      futures/websocket-market-streams/Important-WebSocket-
        //      Change-Notice
        $url = $this->baseURL.'/market/stream?streams='.$streamName;
        $this->messageCount++;
        $this->handleCallback($url, $callbacks);
    }

    public function subscribeToUserStream(string $listenKey, array $callbacks): void
    {
        $this->handleCallback($this->buildUserStreamUrl($listenKey), $callbacks);
    }

    /**
     * Non-blocking variant of subscribeToUserStream. Registers the
     * connection and returns immediately — the caller drives the event
     * loop. Required by the multi-account user-data daemon that hosts
     * one WebSocket per account in a single process; the blocking
     * variant would only ever start the first account.
     */
    public function subscribeToUserStreamAsync(string $listenKey, array $callbacks): void
    {
        $this->handleCallbackAsync($this->buildUserStreamUrl($listenKey), $callbacks);
    }

    /**
     * Build the user-data-stream URL with the listenKey as a query
     * parameter — the post-2026-04-23 shape required by Binance after
     * they decommissioned the legacy `/ws/<listenKey>` path segment.
     */
    private function buildUserStreamUrl(string $listenKey): string
    {
        return $this->baseURL.'/private/ws?listenKey='.rawurlencode($listenKey);
    }

    public function getLoop(): LoopInterface
    {
        return $this->loop;
    }
}
