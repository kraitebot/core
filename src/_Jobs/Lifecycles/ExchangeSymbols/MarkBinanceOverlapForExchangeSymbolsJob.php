<?php

declare(strict_types=1);

namespace Kraite\Core\_Jobs\Lifecycles\ExchangeSymbols;

use Illuminate\Support\Facades\DB;
use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\TokenMapper;

/**
 * MarkBinanceOverlapForExchangeSymbolsJob
 *
 * Marks exchange symbols with `overlaps_with_binance` flag to indicate whether
 * the token exists on Binance. This is important because TAAPI indicators use
 * Binance data as reference - tokens that don't exist on Binance cannot have
 * indicator data.
 *
 * Logic:
 * - Binance symbols: overlaps_with_binance = true (they ARE the reference)
 * - Other exchanges: true if token exists on Binance, false otherwise
 */
final class MarkBinanceOverlapForExchangeSymbolsJob extends BaseQueueableJob
{
    public function relatable()
    {
        return null;
    }

    public function compute()
    {
        return DB::transaction(function () {
            // Get Binance api_system
            $binanceSystem = ApiSystem::where('canonical', 'binance')->first();

            if (! $binanceSystem) {
                return [
                    'error' => 'Binance API system not found',
                    'binance_marked' => 0,
                    'overlapping_marked' => 0,
                    'non_overlapping_marked' => 0,
                ];
            }

            // Get all Binance tokens
            $binanceTokens = ExchangeSymbol::where('api_system_id', $binanceSystem->id)
                ->pluck('token')
                ->toArray();

            // Mark all Binance symbols as overlapping (they ARE the reference)
            $binanceMarked = ExchangeSymbol::where('api_system_id', $binanceSystem->id)
                ->update(['overlaps_with_binance' => true]);

            // Mark other exchanges: true if token exists on Binance
            $overlappingMarked = ExchangeSymbol::where('api_system_id', '!=', $binanceSystem->id)
                ->whereIn('token', $binanceTokens)
                ->update(['overlaps_with_binance' => true]);

            // Mark other exchanges: false if token does NOT exist on Binance
            $nonOverlappingMarked = ExchangeSymbol::where('api_system_id', '!=', $binanceSystem->id)
                ->whereNotIn('token', $binanceTokens)
                ->update(['overlaps_with_binance' => false]);

            // Propagate has_taapi_data from Binance to overlapping symbols
            $taapiPropagated = $this->propagateTaapiDataToOverlappingSymbols($binanceSystem->id);

            return [
                'binance_marked' => $binanceMarked,
                'overlapping_marked' => $overlappingMarked,
                'non_overlapping_marked' => $nonOverlappingMarked,
                'taapi_propagated' => $taapiPropagated,
                'total_binance_tokens' => count($binanceTokens),
                'message' => sprintf(
                    'Marked %d Binance, %d overlapping, %d non-overlapping. Propagated TAAPI data to %d symbols.',
                    $binanceMarked,
                    $overlappingMarked,
                    $nonOverlappingMarked,
                    $taapiPropagated
                ),
            ];
        });
    }

    /**
     * Propagate has_taapi_data from Binance symbols to overlapping non-Binance symbols.
     * Uses TokenMapper for cross-exchange token name mapping (e.g., NEIRO on Binance = 1000NEIRO on KuCoin).
     */
    public function propagateTaapiDataToOverlappingSymbols(int $binanceSystemId): int
    {
        $propagated = 0;

        // Get all Binance symbols with has_taapi_data set
        $binanceSymbols = ExchangeSymbol::where('api_system_id', $binanceSystemId)
            ->whereNotNull('api_statuses->has_taapi_data')
            ->get();

        // Build a lookup map: token => has_taapi_data
        $binanceTaapiData = [];
        foreach ($binanceSymbols as $symbol) {
            $hasData = $symbol->api_statuses['has_taapi_data'] ?? false;
            // Only store if we have a definitive value (prefer true over false if multiple entries)
            if (! isset($binanceTaapiData[$symbol->token]) || $hasData === true) {
                $binanceTaapiData[$symbol->token] = $hasData;
            }
        }

        // Get all token mappings for cross-exchange name differences
        $tokenMappings = TokenMapper::all();
        $mappedTokens = []; // binance_token => [other_api_system_id => other_token]
        foreach ($tokenMappings as $mapping) {
            if (! isset($mappedTokens[$mapping->binance_token])) {
                $mappedTokens[$mapping->binance_token] = [];
            }
            $mappedTokens[$mapping->binance_token][$mapping->other_api_system_id] = $mapping->other_token;
        }

        // Get all non-Binance overlapping symbols that need TAAPI data
        $symbolsToUpdate = ExchangeSymbol::where('api_system_id', '!=', $binanceSystemId)
            ->where('overlaps_with_binance', true)
            ->get();

        foreach ($symbolsToUpdate as $symbol) {
            $hasTaapiData = null;

            // Try direct token match first
            if (isset($binanceTaapiData[$symbol->token])) {
                $hasTaapiData = $binanceTaapiData[$symbol->token];
            } else {
                // Try TokenMapper reverse lookup
                foreach ($mappedTokens as $binanceToken => $exchanges) {
                    if (!(isset($exchanges[$symbol->api_system_id]) && $exchanges[$symbol->api_system_id] === $symbol->token)) {
                        continue;
                    }

                    if (isset($binanceTaapiData[$binanceToken])) {
                            $hasTaapiData = $binanceTaapiData[$binanceToken];
                    }
                        break;
                }
            }

            // Update if we found TAAPI data from Binance
            if ($hasTaapiData !== null) {
                $apiStatuses = $symbol->api_statuses ?? [];
                $apiStatuses['has_taapi_data'] = $hasTaapiData;
                $symbol->updateSaving(['api_statuses' => $apiStatuses]);
                $propagated++;
            }
        }

        return $propagated;
    }
}
