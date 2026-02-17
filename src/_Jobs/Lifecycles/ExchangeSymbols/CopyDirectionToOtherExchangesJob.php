<?php

declare(strict_types=1);

namespace Kraite\Core\_Jobs\Lifecycles\ExchangeSymbols;

use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\TokenMapper;

final class CopyDirectionToOtherExchangesJob extends BaseQueueableJob
{
    public function __construct(
        public int $sourceExchangeSymbolId,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function compute()
    {
        // 1. Load source Binance symbol
        $sourceSymbol = ExchangeSymbol::find($this->sourceExchangeSymbolId);
        if (! $sourceSymbol) {
            return ['error' => 'Source symbol not found'];
        }

        // 2. Get Binance API system ID
        $binanceSystem = ApiSystem::where('canonical', 'binance')->first();
        if (! $binanceSystem || $sourceSymbol->api_system_id !== $binanceSystem->id) {
            return ['skipped' => 'Source symbol is not from Binance'];
        }

        // 3. Skip if no direction to copy
        if (! $sourceSymbol->direction) {
            return ['skipped' => 'No direction set on source symbol'];
        }

        // 4. Find all other exchanges
        $otherExchanges = ApiSystem::where('canonical', '!=', 'binance')
            ->where('is_exchange', true)
            ->get();

        $copiedCount = 0;
        $targetSymbols = [];

        // 5. For each exchange, find matching token (overlaps_with_binance already validates the match)
        foreach ($otherExchanges as $exchange) {
            // Try direct token match first
            $targetSymbol = ExchangeSymbol::query()
                ->where('api_system_id', $exchange->id)
                ->where('token', $sourceSymbol->token)
                ->where('overlaps_with_binance', true)
                ->first();

            // If no direct match, try TokenMapper for exchanges with different token names
            if (! $targetSymbol) {
                $mappedToken = TokenMapper::query()
                    ->where('binance_token', $sourceSymbol->token)
                    ->where('other_api_system_id', $exchange->id)
                    ->first();

                if ($mappedToken) {
                    $targetSymbol = ExchangeSymbol::query()
                        ->where('api_system_id', $exchange->id)
                        ->where('token', $mappedToken->other_token)
                        ->where('overlaps_with_binance', true)
                        ->first();
                }
            }

            // 6. Copy direction data if target found
            if ($targetSymbol) {
                // Clone has_taapi_data status from source symbol
                $sourceHasTaapiData = $sourceSymbol->api_statuses['has_taapi_data'] ?? false;
                $targetApiStatuses = $targetSymbol->api_statuses ?? [];
                $targetApiStatuses['has_taapi_data'] = $sourceHasTaapiData;

                $targetSymbol->updateSaving([
                    'direction' => $sourceSymbol->direction,
                    'indicators_values' => $sourceSymbol->indicators_values,
                    'indicators_timeframe' => $sourceSymbol->indicators_timeframe,
                    'indicators_synced_at' => $sourceSymbol->indicators_synced_at,
                    'api_statuses' => $targetApiStatuses,
                    'has_no_indicator_data' => false,
                    'has_price_trend_misalignment' => false,
                    'has_early_direction_change' => false,
                    'has_invalid_indicator_direction' => false,
                ]);

                $copiedCount++;
                $targetSymbols[] = [
                    'exchange' => $exchange->canonical,
                    'token' => $targetSymbol->token,
                    'quote' => $targetSymbol->quote,
                    'id' => $targetSymbol->id,
                ];
            }
        }

        return [
            'source_symbol_id' => $this->sourceExchangeSymbolId,
            'source_token' => $sourceSymbol->token,
            'source_quote' => $sourceSymbol->quote,
            'direction' => $sourceSymbol->direction,
            'copied_to_count' => $copiedCount,
            'target_symbols' => $targetSymbols,
        ];
    }
}
