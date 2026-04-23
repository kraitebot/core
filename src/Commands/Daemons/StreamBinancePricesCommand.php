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
use Kraite\Core\Support\Proxies\ApiWebsocketProxy;
use Kraite\Core\Support\ValueObjects\ApiCredentials;

/**
 * StreamBinancePricesCommand
 *
 * Long-running daemon that subscribes to Binance's all-symbols mark-price
 * WebSocket stream (1-second cadence) and keeps `exchange_symbols.mark_price`
 * + `mark_price_synced_at` current for every Binance-listed symbol.
 *
 * Runs under supervisor, not the scheduler — the process is expected to be
 * alive continuously. A stopped process is an incident, not a missed tick.
 *
 * Flow:
 * - Build a pair → exchange_symbol_id lookup once at startup (refreshed
 *   every 5 min so newly-discovered symbols start receiving prices without
 *   a restart).
 * - Open the Binance `!markPrice@arr@1s` stream via ApiWebsocketProxy.
 * - On each tick: decode the array of `{s: symbol, p: markPrice}` rows,
 *   batch into 500-row chunked CASE/WHEN UPDATEs against exchange_symbols.
 * - gc_collect_cycles() after each batch to keep the long-running process
 *   memory profile stable.
 *
 * Notes:
 * - Bad JSON and WebSocket errors log to the `jobs` channel; no Pushover
 *   spam for every hiccup, the supervisor handles process-level failure.
 * - UPDATEs bypass Eloquent for speed — no observers fire. The intent here
 *   is pure price refresh, not auditable state change.
 */
final class StreamBinancePricesCommand extends Command
{
    protected $signature = 'kraite:stream-binance-prices';

    protected $description = 'Daemon that streams Binance mark prices into exchange_symbols via WebSocket.';

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

    public function handle(): int
    {
        $account = Account::admin('binance');

        if (! $account) {
            $this->error('No Binance admin account found — cannot authenticate WebSocket.');

            return self::FAILURE;
        }

        $credentials = new ApiCredentials($account->all_credentials);
        $this->binanceSystemId = (int) ApiSystem::firstWhere('canonical', 'binance')?->id;

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
                $decoded = json_decode($msg, true);

                if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
                    Log::channel('jobs')->warning(
                        '[BINANCE-STREAM] Invalid JSON on mark-price frame',
                        ['bytes' => mb_strlen($msg)]
                    );

                    return;
                }

                $prices = [];
                foreach ($decoded as $row) {
                    if (isset($row['s'], $row['p'])) {
                        $prices[$row['s']] = $row['p'];
                    }
                }

                $this->updateExchangeSymbols($prices);
                $this->maybeRefreshPairMap();
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
     */
    private function updateExchangeSymbols(array $prices): void
    {
        $now = now();
        $batch = [];

        foreach ($prices as $pair => $price) {
            if (! isset($this->pairToIds[$pair])) {
                continue;
            }

            foreach ($this->pairToIds[$pair] as $id) {
                $batch[] = ['id' => $id, 'price' => $price];
            }
        }

        if (empty($batch)) {
            return;
        }

        foreach (array_chunk($batch, 500) as $chunk) {
            $ids = array_column($chunk, 'id');
            $case = 'CASE id';
            foreach ($chunk as $row) {
                $case .= ' WHEN '.(int) $row['id'].' THEN '.$this->quoteNumeric($row['price']);
            }
            $case .= ' END';

            $idsList = implode(',', array_map('intval', $ids));
            $ts = $now->toDateTimeString();

            DB::statement(
                "UPDATE exchange_symbols SET mark_price = {$case}, mark_price_synced_at = ? WHERE id IN ({$idsList})",
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
        $otherApiSystemIds = ApiSystem::where('id', '!=', $this->binanceSystemId)->pluck('id');

        $otherSymbolsByKey = ExchangeSymbol::where('api_system_id', '!=', $this->binanceSystemId)
            ->get()
            ->groupBy(fn ($symbol) => "{$symbol->api_system_id}:{$symbol->token}:{$symbol->quote}");

        $tokenMappersByBinanceToken = TokenMapper::all()->groupBy('binance_token');

        $map = [];
        foreach (ExchangeSymbol::where('api_system_id', $this->binanceSystemId)->get() as $binance) {
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
    }

    private function maybeRefreshPairMap(): void
    {
        // Refresh every 5 minutes so newly-discovered symbols start
        // receiving prices without a daemon restart.
        if (time() - $this->lastPairMapRefreshAt >= 300) {
            $this->refreshPairMap();
        }
    }

    private function quoteNumeric(mixed $value): string
    {
        return is_numeric($value) ? (string) $value : '0';
    }
}
