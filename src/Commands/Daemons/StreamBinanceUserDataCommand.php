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
use Kraite\Core\Support\NotificationService;
use Kraite\Core\Support\ValueObjects\ApiCredentials;
use React\EventLoop\Loop;
use StepDispatcher\Models\Step;
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

    protected $signature = 'kraite:stream-binance-user-data';

    protected $description = 'Daemon hosting one authenticated Binance user-data WebSocket per account; dispatches every frame as a Step on the user-data queue.';

    /**
     * Per-account WebSocket client slots, keyed by account_id. Existence
     * of a slot means the daemon has already initialised that account
     * in this process — discovery uses this map to skip initialised
     * accounts on each sweep.
     *
     * @var array<int, BinanceApiClient>
     */
    private array $accountClients = [];

    private ?int $binanceSystemId = null;

    public function handle(): int
    {
        $binanceSystem = ApiSystem::firstWhere('canonical', 'binance');

        if ($binanceSystem === null) {
            $this->error('No api_systems row with canonical=binance — refusing to start.');

            return self::FAILURE;
        }

        $this->binanceSystemId = $binanceSystem->id;

        $loop = Loop::get();

        $loop->addPeriodicTimer(self::DISCOVERY_INTERVAL_SECONDS, function () {
            $this->discoverNewAccounts();
        });

        $this->discoverNewAccounts();

        Log::channel('user-data')->info('[USER-DATA] daemon started', [
            'initial_account_count' => count($this->accountClients),
        ]);

        $loop->run();

        return self::SUCCESS;
    }

    /**
     * Walk the accounts table and init any Binance account that does
     * not yet have an active client slot in this process. Per-account
     * try/catch keeps a single failure from aborting the whole sweep.
     */
    private function discoverNewAccounts(): void
    {
        $accounts = Account::query()
            ->where('api_system_id', $this->binanceSystemId)
            ->whereNotNull('binance_api_key')
            ->get();

        foreach ($accounts as $account) {
            if (isset($this->accountClients[$account->id])) {
                continue;
            }

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
            }
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
            },
            'error' => function ($conn, Throwable $exception) use ($account) {
                Log::channel('user-data')->error('[USER-DATA] connection error', [
                    'account_id' => $account->id,
                    'error' => $exception->getMessage(),
                ]);
            },
        ]);

        $this->accountClients[$account->id] = $client;

        Log::channel('user-data')->info('[USER-DATA] account initialised', [
            'account_id' => $account->id,
            'account_name' => $account->name,
        ]);

        $this->notify(
            'binance_user_data_account_connected',
            ['account_id' => $account->id, 'account_name' => $account->name],
            cacheKey: ['account_id' => (string) $account->id]
        );
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

        if (($payload['e'] ?? null) === 'listenKeyExpired') {
            $this->handleListenKeyExpired($account);
        }

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

        unset($this->accountClients[$account->id]);

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
