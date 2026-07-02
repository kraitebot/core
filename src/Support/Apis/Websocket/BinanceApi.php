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
            // Mark-price stream is strict-data (1Hz expected). Pings
            // do NOT count as "alive" — a server-pinging-but-no-data
            // failure must trip the idle watchdog. The user-data
            // daemon constructs its own BinanceApiClient directly and
            // leaves the default true so a legitimately silent
            // account doesn't false-trip.
            'pings_count_as_alive' => false,

            // Same strict-data rationale drives the self-exit: if no
            // mark-price frame lands for 5 minutes, a reconnect loop is
            // not recovering (2026-07-02 DNS-wedge). Exit so supervisor
            // respawns a fresh process — the only reliable way to clear a
            // loop-level ReactPHP DNS/UDP wedge. User-data leaves this
            // unset (silence is normal there) so it never self-exits.
            'no_data_self_exit_seconds' => 300,
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
