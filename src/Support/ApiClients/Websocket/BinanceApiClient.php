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
        // User data streams route under /private after the 2026-04-23
        // migration. Note the listenKey is now passed as a QUERY
        // PARAMETER (`?listenKey=<key>`) — the legacy path-segment
        // shape (`/ws/<listenKey>`) is decommissioned.
        $url = $this->baseURL.'/private/ws?listenKey='.rawurlencode($listenKey);
        $this->handleCallback($url, $callbacks);
    }

    public function getLoop(): LoopInterface
    {
        return $this->loop;
    }
}
