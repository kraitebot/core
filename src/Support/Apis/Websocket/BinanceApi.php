<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Apis\Websocket;

use Kraite\Core\Support\ApiClients\Websocket\BinanceApiClient;
use Kraite\Core\Support\ValueObjects\ApiCredentials;
use React\EventLoop\LoopInterface;

/**
 * BinanceApi (WebSocket)
 *
 * Thin Binance-flavoured façade over the websocket client. Callers express
 * intent ("give me mark prices", "give me the user stream") and this class
 * resolves the Binance-specific stream name + setup ceremony.
 */
final class BinanceApi
{
    private BinanceApiClient $client;

    public function __construct(ApiCredentials $credentials)
    {
        $this->client = new BinanceApiClient([
            'base_url' => config('kraite.api.url.binance.stream', 'wss://fstream.binance.com'),
            'api_key' => $credentials->get('binance_api_key'),
            'api_secret' => $credentials->get('binance_api_secret'),
        ]);
    }

    /**
     * Subscribe to Binance's all-symbols mark-price stream.
     *
     * @param  bool  $prefersSlowerUpdate  Uses the 3-second cadence stream instead of the 1-second variant. Useful for reducing DB churn on low-priority tasks.
     */
    public function markPrices(array $callbacks, bool $prefersSlowerUpdate = false): void
    {
        $streamName = $prefersSlowerUpdate ? '!markPrice@arr' : '!markPrice@arr@1s';
        $this->client->subscribeToStream($streamName, $callbacks);
    }

    public function getLoop(): LoopInterface
    {
        return $this->client->getLoop();
    }
}
