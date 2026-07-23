<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Daemons;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\TokenMapper;
use Kraite\Core\Support\FreezeMode;
use Kraite\Core\Support\Math;
use Kraite\Core\Support\Proxies\ApiWebsocketProxy;
use Kraite\Core\Support\ValueObjects\ApiCredentials;
use React\EventLoop\Loop;
use Throwable;

/**
 * StreamBinancePricesCommand
 *
 * Long-running daemon that subscribes to Binance's all-symbols mark-price
 * WebSocket stream (1-second cadence) and keeps the
 * `exchange_symbol_prices` sidecar (`mark_price` + `mark_price_synced_at`)
 * current for every Binance-listed symbol AND for peer rows on Bybit /
 * Bitget / KuCoin that map to the same underlying token+quote.
 *
 * Runs under supervisor, not the scheduler — the process is expected to be
 * alive continuously. A stopped process is an incident, not a missed tick.
 *
 * Flow:
 * - Build a pair → exchange_symbol_id lookup once at startup (refreshed
 *   every 5 min so newly-discovered symbols start receiving prices without
 *   a restart).
 * - Open the Binance `!markPrice@arr@1s` stream via ApiWebsocketProxy.
 * - On each tick: decode the array of `{s: symbol, p: markPrice}` rows.
 * - Coalesce frames to one DB flush every WRITE_INTERVAL_SECONDS (5s).
 *   Intermediate frames are dropped — the next accepted frame carries the
 *   freshest price for every symbol the bot tracks, so no information is
 *   lost, only the cadence at which it lands in the DB is reduced.
 * - On the accepted frame: batch into 500-row chunked CASE/WHEN UPDATEs
 *   against `exchange_symbol_prices` (writes go to the sidecar — not
 *   `exchange_symbols` — so the daemon's row locks don't contend with
 *   indicator pipeline / direction-burst writers; ref:
 *   db_lock_contention_mark_price_daemon.md, 2026-05-04).
 * - gc_collect_cycles() after each batch to keep the long-running process
 *   memory profile stable.
 *
 * Notes:
 * - Bad JSON and WebSocket errors log to the `jobs` channel; no Pushover
 *   spam for every hiccup, the supervisor handles process-level failure.
 * - UPDATEs bypass Eloquent for speed — no observers fire. The intent here
 *   is pure price refresh, not auditable state change.
 * - `ExchangeSymbol::$mark_price` reads are proxied through the model
 *   accessor onto the sidecar, so all consumers stay zero-touch.
 */
final class StreamBinancePricesCommand extends Command
{
    protected $signature = 'kraite:stream-binance-prices';

    protected $description = 'Daemon that streams Binance mark prices into the exchange_symbol_prices sidecar via WebSocket (5s coalesce cadence).';

    /**
     * Minimum interval between persistence flushes, in seconds. Binance's
     * `!markPrice@arr@1s` stream emits a full universe snapshot every
     * second, but the bot's local read paths (slot allocation, dashboard,
     * stress-fill, BSCS) tolerate single-digit-second staleness and the
     * exchange-side TP / SL / limit triggers operate independently of
     * `mark_price`. Persisting every tick generated ~2,500 row writes/sec
     * sustained across the 2257-symbol universe — pure background pressure
     * with no operational benefit. Coalescing to once per 5s reduces the
     * write rate ~5× while keeping mark prices fresh enough for every
     * downstream consumer.
     */
    private const WRITE_INTERVAL_SECONDS = 5;

    /**
     * Maximum consecutive frame-processing failures the daemon tolerates
     * before exiting for supervisor respawn.
     *
     * Same shape as the user-data daemon's memory-pressure self-exit
     * (StreamBinanceUserDataCommand:622-647): one transient DB blip
     * should not kill price streaming permanently, but a sustained
     * write-failure window means the loop is stuck and a fresh process
     * is the right recovery. Counter resets to 0 on every successful
     * flush.
     */
    private const MAX_CONSECUTIVE_WRITE_FAILURES = 5;

    /**
     * Binance parsed_trading_pair (e.g. "SOLUSDT") → list of exchange_symbol
     * ids that represent the same underlying token+quote across every
     * exchange we have ingested. One Binance tick refreshes the mark_price
     * on the Binance row AND every peer row on Bybit / KuCoin / Bitget so
     * the selection phase can work against fresh data regardless of which
     * exchange ends up being asked to open the position.
     *
     * @var array<string, list<int>>
     */
    private array $pairToIds = [];

    private ?int $binanceSystemId = null;

    private int $lastPairMapRefreshAt = 0;

    /**
     * Monotonic clock (microtime as float) of the last successful flush
     * to `exchange_symbol_prices`. Frames arriving within
     * WRITE_INTERVAL_SECONDS of the last flush are dropped — the next
     * accepted frame carries the freshest snapshot for every symbol so
     * no information is lost, only the cadence at which it lands in the
     * DB is reduced.
     */
    private float $lastWriteAt = 0.0;

    /**
     * Consecutive frame-processing failures since the last successful
     * flush. Reset to 0 in the success path; incremented in the catch
     * block. When it reaches MAX_CONSECUTIVE_WRITE_FAILURES the daemon
     * stops the loop so supervisor's autorestart=true respawns a fresh
     * process — same recovery shape as the user-data daemon's memory
     * limit auto-exit.
     */
    private int $writeFailureCount = 0;

    public function handle(): int
    {
        if (FreezeMode::isActive()) {
            $this->warn('Kraite is frozen. Mark-price stream waiting without connecting.');

            if (app()->environment('testing')) {
                return self::SUCCESS;
            }

            while (FreezeMode::isActive()) {
                sleep(1);
            }
        }

        $account = Account::admin('binance');

        if (! $account) {
            $this->error('No Binance admin account found — cannot authenticate WebSocket.');

            return self::FAILURE;
        }

        $credentials = new ApiCredentials($account->all_credentials);
        $this->binanceSystemId = (int) ApiSystem::active()->canonical('binance')->first()?->id;

        if ($this->binanceSystemId === 0) {
            $this->error('No Binance api_system row found.');

            return self::FAILURE;
        }

        $this->refreshPairMap();

        $websocketProxy = new ApiWebsocketProxy('binance', $credentials);

        Log::channel('jobs')->info(
            '[BINANCE-STREAM] Subscribing to !markPrice@arr@1s',
            [
                'binance_pairs' => count($this->pairToIds),
                'total_replicated_rows' => array_sum(array_map('count', $this->pairToIds)),
            ]
        );

        $websocketProxy->markPrices([
            'message' => function ($conn, $msg) {
                // Failure-containment contract for the price-stream hot
                // path. Mirrors the shape used by the user-data daemon
                // (StreamBinanceUserDataCommand::onMessage), the
                // ApiRequestLogObserver per-branch wrap, and
                // NotificationService::sendToSpecificUser: any throw
                // inside the message callback is caught and logged.
                // ReactPHP / Pawl have no global error boundary inside
                // the loop — an uncaught exception here can tear down
                // the connection or wedge the loop, killing the always-
                // on price feed. The catch keeps the daemon alive on
                // transient failures (DB blip, deadlock, metadata lock)
                // and exits intentionally after a sustained failure
                // window so supervisor respawns a fresh process.
                try {
                    $decoded = json_decode($msg, true);

                    if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
                        Log::channel('jobs')->warning(
                            '[BINANCE-STREAM] Invalid JSON on mark-price frame',
                            ['bytes' => mb_strlen($msg)]
                        );

                        return;
                    }

                    // Coalesce frames to one DB flush every WRITE_INTERVAL_SECONDS.
                    // Binance sends a full snapshot of every symbol on every
                    // tick, so dropping intermediate frames loses no
                    // information — the next accepted frame carries the
                    // freshest price for every symbol the bot tracks. The
                    // map refresh runs unconditionally so newly-discovered
                    // symbols still come online inside the 5-min refresh
                    // window even during an idle persistence interval.
                    $now = microtime(true);
                    if ($now - $this->lastWriteAt < self::WRITE_INTERVAL_SECONDS) {
                        $this->maybeRefreshPairMap();

                        return;
                    }

                    // Combined-stream envelope: `{"stream": "...", "data": [...]}`.
                    // The single-stream shape (bare array) is no longer in use
                    // after the 2026-04-24 switch to `/stream?streams=` but we
                    // accept it as a fallback so a server-side rollback doesn't
                    // silently drop writes again.
                    $rows = isset($decoded['data']) && is_array($decoded['data'])
                        ? $decoded['data']
                        : $decoded;

                    $prices = [];
                    foreach ($rows as $row) {
                        if (is_array($row) && isset($row['s'], $row['p'])) {
                            $prices[$row['s']] = $row['p'];
                        }
                    }

                    $this->updateExchangeSymbols($prices);

                    // Success path: lastWriteAt + counter reset only run
                    // when updateExchangeSymbols completes without
                    // throwing. A failed flush leaves lastWriteAt
                    // unchanged so the next frame retries immediately
                    // (no 5-sec coalesce penalty on the recovery side)
                    // and resets the failure counter only on real
                    // success — preventing a slow degrade where every
                    // other flush succeeds and the counter never
                    // reaches the exit threshold.
                    $this->lastWriteAt = $now;
                    $this->writeFailureCount = 0;

                    $this->maybeRefreshPairMap();
                } catch (Throwable $exception) {
                    $this->writeFailureCount++;

                    Log::channel('jobs')->error(
                        '[BINANCE-STREAM] frame processing threw — swallowed to keep loop alive',
                        [
                            'consecutive_failures' => $this->writeFailureCount,
                            'threshold' => self::MAX_CONSECUTIVE_WRITE_FAILURES,
                            'exception' => $exception::class,
                            'message' => $exception->getMessage(),
                        ]
                    );

                    if ($this->writeFailureCount >= self::MAX_CONSECUTIVE_WRITE_FAILURES) {
                        Log::channel('jobs')->warning(
                            '[BINANCE-STREAM] consecutive failure threshold exceeded — exiting for supervisor respawn',
                            ['failures' => $this->writeFailureCount]
                        );

                        Loop::get()->stop();
                    }
                }
            },
            'ping' => function () {},
            'close' => function () {
                Log::channel('jobs')->warning('[BINANCE-STREAM] WebSocket closed — base client will reconnect');
            },
            'error' => function ($conn, $e) {
                Log::channel('jobs')->error(
                    '[BINANCE-STREAM] WebSocket error',
                    ['error' => $e->getMessage()]
                );
            },
        ]);

        return self::SUCCESS;
    }

    /**
     * Bulk UPDATE via CASE/WHEN chunked at 500 rows per statement.
     *
     * Bypasses Eloquent because observers have no useful role on a 1Hz
     * price refresh — their DB-log cost and model-hydration overhead
     * would dominate the daemon's CPU budget. Raw UPDATE keeps the write
     * path deterministic and cheap.
     *
     * Cutover note (2026-05-04): writes go to the dedicated
     * `exchange_symbol_prices` sidecar instead of `exchange_symbols`.
     * The sidecar has no other writers, so the daemon's row locks
     * no longer contend with the indicator pipeline / direction
     * burst at :30 / api_request_logs INSERTs. Memory ref:
     * db_lock_contention_mark_price_daemon.md. Reads on
     * `ExchangeSymbol::$mark_price` are proxied through the
     * accessor on the model so consumers stay zero-touch.
     */
    private function updateExchangeSymbols(array $prices): void
    {
        $now = now();
        $batch = [];
        $skipped = 0;

        foreach ($prices as $pair => $price) {
            if (! isset($this->pairToIds[$pair])) {
                continue;
            }

            // Reject malformed prices BEFORE they reach the SQL builder.
            // `Math::isPositive` covers null, empty string, non-numeric,
            // zero, and negative — all the shapes that previously
            // collapsed to '0' via the old `quoteNumeric` fallback and
            // produced fresh-timestamp/zero-price rows in the sidecar
            // (a freshness check passes while consumers read a
            // nonsensical value). Skipping preserves the previous
            // valid `mark_price` + `mark_price_synced_at` for that
            // symbol, which is strictly safer than overwriting.
            if (! Math::isPositive($price)) {
                $skipped++;

                continue;
            }

            foreach ($this->pairToIds[$pair] as $id) {
                $batch[] = ['id' => $id, 'price' => (string) $price];
            }
        }

        if ($skipped > 0) {
            Log::channel('price-stream')->warning('[PRICE-STREAM] skipped non-positive prices', [
                'skipped_pairs' => $skipped,
            ]);
        }

        if (empty($batch)) {
            return;
        }

        foreach (array_chunk($batch, 500) as $chunk) {
            $ids = array_column($chunk, 'id');
            $case = 'CASE exchange_symbol_id';
            foreach ($chunk as $row) {
                // Price already validated as positive above. The cast to
                // string is just to satisfy the SQL builder; bcmath has
                // already proven it's a valid numeric.
                $case .= ' WHEN '.(int) $row['id'].' THEN '.$row['price'];
            }
            $case .= ' END';

            $idsList = implode(',', array_map('intval', $ids));
            $ts = $now->toDateTimeString();

            DB::statement(
                "UPDATE exchange_symbol_prices SET mark_price = {$case}, mark_price_synced_at = ? WHERE exchange_symbol_id IN ({$idsList})",
                [$ts]
            );
        }

        unset($batch);
        gc_collect_cycles();
    }

    /**
     * Build the pair→ids map keyed by Binance's `parsed_trading_pair`.
     *
     * For each Binance symbol we collect every exchange_symbol id that
     * tracks the same underlying token+quote:
     * 1. The Binance row itself.
     * 2. Rows on every other exchange where `token` + `quote` match
     *    directly (SOL/USDT on Binance → SOL/USDT on Bybit / Bitget /
     *    KuCoin, etc.).
     * 3. Rows resolved via `token_mappers` overrides — Binance's
     *    `1000SATS` maps to KuCoin's `10000SATS`, Binance's `BTC` maps
     *    to KuCoin's `XBT`, and so on. Quote must still match.
     *
     * Non-Binance-listed tokens don't appear as keys because they're
     * already filtered out of the selection pipeline upstream, so
     * there's no symbol to replicate to.
     */
    private function refreshPairMap(): void
    {
        // Column projection: the map only needs id / api_system_id / token /
        // quote. ExchangeSymbol carries 5+ JSON blobs (api_statuses,
        // leverage_brackets, btc_correlation_*, btc_elasticity_*,
        // indicators_values, symbol_information) that get hydrated and
        // discarded otherwise. At production scale (~10k rows) projecting
        // cuts hydration memory ~7× and JSON-decode time materially —
        // important here because this method runs synchronously inside the
        // ReactPHP message callback and any stall blocks WS keepalive.
        $startedAt = microtime(true);

        $otherApiSystemIds = ApiSystem::activeExchange()
            ->where('id', '!=', $this->binanceSystemId)
            ->pluck('id');

        $otherSymbolsByKey = ExchangeSymbol::onActiveApiSystem()
            ->where('api_system_id', '!=', $this->binanceSystemId)
            ->get(['id', 'api_system_id', 'token', 'quote'])
            ->groupBy(fn ($symbol) => "{$symbol->api_system_id}:{$symbol->token}:{$symbol->quote}");

        $tokenMappersByBinanceToken = TokenMapper::query()
            ->get(['binance_token', 'other_token', 'other_api_system_id'])
            ->groupBy('binance_token');

        $map = [];
        $binanceRows = ExchangeSymbol::where('api_system_id', $this->binanceSystemId)
            ->get(['id', 'api_system_id', 'token', 'quote']);
        foreach ($binanceRows as $binance) {
            $pair = $binance->parsed_trading_pair;
            if ($pair === null) {
                continue;
            }

            $ids = [(int) $binance->id];

            foreach ($otherApiSystemIds as $otherApiSystemId) {
                $directKey = "{$otherApiSystemId}:{$binance->token}:{$binance->quote}";
                foreach ($otherSymbolsByKey[$directKey] ?? [] as $other) {
                    $ids[] = (int) $other->id;
                }
            }

            foreach ($tokenMappersByBinanceToken[$binance->token] ?? [] as $mapper) {
                $mappedKey = "{$mapper->other_api_system_id}:{$mapper->other_token}:{$binance->quote}";
                foreach ($otherSymbolsByKey[$mappedKey] ?? [] as $other) {
                    $ids[] = (int) $other->id;
                }
            }

            $map[$pair] = array_values(array_unique($ids));
        }

        $this->pairToIds = $map;
        $this->lastPairMapRefreshAt = time();

        // Duration + counts logged at info level so growing event-loop
        // stall is visible in the jobs channel before it becomes a
        // production incident. Symbol universe grows ~linearly with new
        // exchange listings; this is the trip-wire to know when the
        // refresh is cheap enough to keep inline vs. when it should be
        // moved out of the ReactPHP callback.
        Log::channel('jobs')->info(
            '[BINANCE-STREAM] Pair map rebuilt',
            [
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                'binance_pairs' => count($map),
                'other_symbols_loaded' => $otherSymbolsByKey->sum(fn ($g) => $g->count()),
                'token_mappers_loaded' => $tokenMappersByBinanceToken->sum(fn ($g) => $g->count()),
                'total_replicated_rows' => array_sum(array_map('count', $map)),
            ]
        );
    }

    private function maybeRefreshPairMap(): void
    {
        // Refresh every 5 minutes so newly-discovered symbols start
        // receiving prices without a daemon restart.
        if (time() - $this->lastPairMapRefreshAt >= 300) {
            $this->refreshPairMap();
        }
    }
}
