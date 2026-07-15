<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\ApiSystem;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Support\Proxies\TradingMapperProxy;

/**
 * UpsertExchangeSymbolsFromExchangeJob
 *
 * Fetches all available symbols from an exchange API and upserts them
 * directly into exchange_symbols table with token, quote, and metadata.
 *
 * This is the simplified approach that replaces the old CMC-lookup workflow.
 * No symbol table lookup needed - we store token/quote directly.
 */
final class UpsertExchangeSymbolsFromExchangeJob extends BaseApiableJob
{
    public ApiSystem $apiSystem;

    public function __construct(int $apiSystemId)
    {
        $this->apiSystem = ApiSystem::findOrFail($apiSystemId);
    }

    public function relatable(): ApiSystem
    {
        return $this->apiSystem;
    }

    public function assignExceptionHandler(): void
    {
        $canonical = $this->apiSystem->canonical;
        $this->exceptionHandler = BaseExceptionHandler::make($canonical)
            ->withAccount(Account::admin($canonical));
    }

    public function startOrFail(): bool
    {
        return (bool) $this->apiSystem->is_exchange;
    }

    /**
     * @return array<string, mixed>
     */
    public function computeApiable(): array
    {
        $apiResponse = $this->apiSystem->apiQueryMarketData();

        $results = $apiResponse->result;

        if (app()->environment('local')) {
            $allowed = config('kraite.local_symbols', []);
            $results = array_filter($results, function (array $symbolData) use ($allowed): bool {
                return in_array($symbolData['baseAsset'] ?? '', $allowed, true);
            });
        }

        return $this->synchronizeExchangeSymbols(array_values($results));
    }

    /**
     * Reconcile canonical exchange catalogue rows with local lifecycle state.
     *
     * @param  array<int, array<string, mixed>>  $results
     * @return array<string, mixed>
     */
    public function synchronizeExchangeSymbols(array $results): array
    {
        $totalFromApi = count($results);

        $symbolsByToken = Symbol::pluck('id', 'token');
        $tradingMapper = new TradingMapperProxy($this->apiSystem->canonical);

        $upsertedCount = 0;
        $linkedCount = 0;
        $skippedCount = 0;

        // No outer transaction: the 1Hz mark-price writer must be able to
        // interleave between catalogue rows. A partial run is recovered by
        // the next idempotent hourly reconciliation.
        foreach ($results as $symbolData) {
            $token = $symbolData['baseAsset'] ?? null;
            $quote = $symbolData['quoteAsset'] ?? null;
            $asset = $symbolData['pair'] ?? null; // Raw exchange pair (e.g., PF_XBTUSD, BTCUSDT)

            if (! $token || ! $quote) {
                $skippedCount++;

                continue;
            }

            $pricePrecision = $symbolData['pricePrecision'] ?? null;
            $quantityPrecision = $symbolData['quantityPrecision'] ?? null;

            if ($pricePrecision !== null && $pricePrecision < 0) {
                $pricePrecision = 0;
            }
            if ($quantityPrecision !== null && $quantityPrecision < 0) {
                $quantityPrecision = 0;
            }

            $existingSymbol = ExchangeSymbol::where('token', $token)
                ->where('api_system_id', $this->apiSystem->id)
                ->where('quote', $quote)
                ->first();

            $isTrading = (bool) ($symbolData['isTrading'] ?? true);
            $isEligible = (bool) ($symbolData['isEligible'] ?? true);

            // Ineligible rows are catalogue evidence only. Inactive eligible
            // rows update known pairs but are not created from scratch.
            if (! $isEligible || ($existingSymbol === null && ! $isTrading)) {
                $skippedCount++;

                continue;
            }

            $symbolId = $symbolsByToken->get($token);
            $deliveryTimestampMs = is_numeric($symbolData['deliveryDate'] ?? null)
                ? (int) $symbolData['deliveryDate']
                : null;
            $normalizedDeliveryTimestampMs = $tradingMapper->normalizeDeliveryTimestampMs($deliveryTimestampMs);
            $deliveryAt = $normalizedDeliveryTimestampMs !== null
                ? Carbon::createFromTimestampMs($normalizedDeliveryTimestampMs, config('app.timezone'))
                : null;

            if ((bool) ($symbolData['isDelisted'] ?? false)
                && ($deliveryAt === null || $deliveryAt->isFuture())) {
                $deliveryAt = now();
            }

            $isMarkedForDelisting = ! $isTrading || $deliveryAt !== null;

            $updateData = [
                'asset' => $asset,
                'delivery_ts_ms' => $deliveryTimestampMs,
                'delivery_at' => $deliveryAt,
                'is_marked_for_delisting' => $isMarkedForDelisting,
                'symbol_information' => $symbolData,
            ];

            $metadata = [
                'pricePrecision' => ['price_precision', $pricePrecision],
                'quantityPrecision' => ['quantity_precision', $quantityPrecision],
                'tickSize' => ['tick_size', $symbolData['tickSize'] ?? null],
                'minNotional' => ['min_notional', $symbolData['minNotional'] ?? null],
                'minPrice' => ['min_price', $symbolData['minPrice'] ?? null],
                'maxPrice' => ['max_price', $symbolData['maxPrice'] ?? null],
                'kucoinLotSize' => ['kucoin_lot_size', $symbolData['kucoinLotSize'] ?? null],
                'kucoinMultiplier' => ['kucoin_multiplier', $symbolData['kucoinMultiplier'] ?? null],
            ];

            foreach ($metadata as $source => [$column, $value]) {
                if (array_key_exists($source, $symbolData)) {
                    $updateData[$column] = $value;
                }
            }

            if (! $existingSymbol || ($existingSymbol->symbol_id === null && $symbolId !== null)) {
                $updateData['symbol_id'] = $symbolId;
                if ($symbolId) {
                    $linkedCount++;
                }
            } elseif ($existingSymbol->symbol_id !== null) {
                // Already linked, count it
                $linkedCount++;
            }

            ExchangeSymbol::updateOrCreate(
                [
                    'token' => $token,
                    'api_system_id' => $this->apiSystem->id,
                    'quote' => $quote,
                ],
                $updateData
            );

            $upsertedCount++;
        }

        $markedForDelistingCount = $this->flagMissingSymbolsForDelisting($results);

        return [
            'exchange' => $this->apiSystem->canonical,
            'upserted' => $upsertedCount,
            'linked_to_symbols' => $linkedCount,
            'skipped' => $skippedCount,
            'marked_for_delisting' => $markedForDelistingCount,
            'total_from_api' => $totalFromApi,
        ];
    }

    /**
     * Flag every ExchangeSymbol belonging to this api_system that is absent
     * from the latest exchange response and exclude it from new-trading work.
     * Operational scopes still retain rows carrying open positions.
     *
     * An empty response is treated as an API anomaly (partial outage, etc.)
     * rather than a mass delisting event: we do not flag anything in that
     * case — a later run with real data will do the right thing.
     *
     * Full catalogues (Binance and Bitget) make absence terminal. Active-only
     * catalogues (Bybit and KuCoin) only prove temporary unavailability; a
     * later symbol-specific terminal API error supplies the terminal date.
     *
     * The mapped rows carry at minimum `baseAsset` and `quoteAsset`.
     *
     * @param  array<int, array<string, mixed>>  $apiResult
     * @return int Number of missing rows whose automatic lifecycle state changed.
     */
    public function flagMissingSymbolsForDelisting(array $apiResult): int
    {
        if ($apiResult === []) {
            return 0;
        }

        // "token|quote" composite keys of everything the exchange still lists.
        $liveKeys = collect($apiResult)
            ->map(static function (array $row): string {
                return ($row['baseAsset'] ?? '').'|'.($row['quoteAsset'] ?? '');
            })
            ->unique()
            ->all();
        $missingIsTerminal = (new TradingMapperProxy($this->apiSystem->canonical))
            ->missingFromCatalogueIsTerminal();

        // No pessimistic lock here on purpose — the Binance mark-price
        // WebSocket daemon writes to every exchange_symbol row at 1 Hz
        // (cross-exchange price replication), and a FOR UPDATE table-
        // scan lock on the ~590-row exchange slice contended with those
        // writes for 14+ seconds in production on 2026-04-25. The
        // orphan-marking is idempotent, and the cron runs hourly with
        // withoutOverlapping() so no concurrent run races the same exchange.
        return DB::transaction(function () use ($liveKeys, $missingIsTerminal): int {
            $orphans = ExchangeSymbol::query()
                ->where('api_system_id', $this->apiSystem->id)
                ->get()
                ->filter(static function (ExchangeSymbol $symbol) use ($liveKeys, $missingIsTerminal): bool {
                    $isMissing = ! in_array(
                        needle: $symbol->token.'|'.$symbol->quote,
                        haystack: $liveKeys,
                        strict: true
                    );

                    if (! $isMissing) {
                        return false;
                    }

                    return ! $symbol->is_marked_for_delisting
                        || ($missingIsTerminal && ! $symbol->isDelisted());
                });

            foreach ($orphans as $orphan) {
                $updates = ['is_marked_for_delisting' => true];

                if ($missingIsTerminal) {
                    $updates['delivery_at'] = now();
                }

                $orphan->update($updates);
            }

            return $orphans->count();
        });
    }
}
