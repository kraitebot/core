<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\ApiSystem;

use Illuminate\Support\Facades\DB;
use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Symbol;

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
        // Fetch all symbols from the exchange
        // Mappers already filter out:
        // - Symbols with underscores (test/synthetic symbols)
        // - Non-perpetual contracts (only LinearPerpetual for Bybit, PERPETUAL for Binance)
        // - Non-trading status symbols
        $apiResponse = $this->apiSystem->apiQueryMarketData();

        $totalFromApi = count($apiResponse->result);

        // Pre-load all symbols indexed by token for efficient lookup
        $symbolsByToken = Symbol::pluck('id', 'token');

        $upsertedCount = 0;
        $linkedCount = 0;
        $skippedCount = 0;

        // Wrap entire upsert loop in a single transaction to:
        // 1. Reduce observer overhead (fires once at commit instead of 601 times)
        // 2. Prevent lock contention when multiple jobs run concurrently
        // 3. Ensure atomic operation (all symbols or none)
        DB::transaction(function () use ($apiResponse, $symbolsByToken, &$upsertedCount, &$linkedCount, &$skippedCount) {
            foreach ($apiResponse->result as $symbolData) {
                $token = $symbolData['baseAsset'] ?? null;
                $quote = $symbolData['quoteAsset'] ?? null;
                $asset = $symbolData['pair'] ?? null; // Raw exchange pair (e.g., PF_XBTUSD, BTCUSDT)

                if (! $token || ! $quote) {
                    $skippedCount++;

                    continue;
                }

                // Upsert exchange symbol with all available metadata
                // Ensure precision values are non-negative (some exchanges return negative values)
                $pricePrecision = $symbolData['pricePrecision'] ?? null;
                $quantityPrecision = $symbolData['quantityPrecision'] ?? null;

                if ($pricePrecision !== null && $pricePrecision < 0) {
                    $pricePrecision = 0;
                }
                if ($quantityPrecision !== null && $quantityPrecision < 0) {
                    $quantityPrecision = 0;
                }

                // Check if exchange symbol already exists
                $existingSymbol = ExchangeSymbol::where('token', $token)
                    ->where('api_system_id', $this->apiSystem->id)
                    ->where('quote', $quote)
                    ->first();

                // Try to find matching symbol_id by token (for new records or unlinked ones)
                $symbolId = $symbolsByToken->get($token);

                // Build update data - always update metadata
                $updateData = [
                    'asset' => $asset,
                    'price_precision' => $pricePrecision,
                    'quantity_precision' => $quantityPrecision,
                    'tick_size' => $symbolData['tickSize'] ?? null,
                    'min_notional' => $symbolData['minNotional'] ?? null,
                    'min_price' => $symbolData['minPrice'] ?? null,
                    'max_price' => $symbolData['maxPrice'] ?? null,
                    'delivery_ts_ms' => $symbolData['deliveryDate'] ?? null,
                    'symbol_information' => $symbolData,

                    // Exchange-specific min order size fields (KuCoin)
                    'kucoin_lot_size' => $symbolData['kucoinLotSize'] ?? null,
                    'kucoin_multiplier' => $symbolData['kucoinMultiplier'] ?? null,
                ];

                // Only set symbol_id if:
                // 1. This is a new record (existingSymbol is null), OR
                // 2. Existing record has no symbol_id and we found one
                // Never overwrite an existing symbol_id (it may have been set via CMC API)
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
        });

        return [
            'exchange' => $this->apiSystem->canonical,
            'upserted' => $upsertedCount,
            'linked_to_symbols' => $linkedCount,
            'skipped' => $skippedCount,
            'total_from_api' => $totalFromApi,
        ];
    }
}
