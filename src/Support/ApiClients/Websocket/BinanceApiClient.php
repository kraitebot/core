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

        $url = $this->baseURL."/ws/{$streamName}";
        $this->messageCount++;
        $this->handleCallback($url, $callbacks);
    }

    public function subscribeToUserStream(string $listenKey, array $callbacks): void
    {
        $url = $this->baseURL."/ws/{$listenKey}";
        $this->handleCallback($url, $callbacks);
    }

    public function getLoop(): LoopInterface
    {
        return $this->loop;
    }
}
