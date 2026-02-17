<?php

declare(strict_types=1);

namespace Kraite\Core\_Jobs\Lifecycles\ExchangeSymbols;

use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\_Jobs\Models\ExchangeSymbol\TouchTaapiDataForExchangeSymbolJob;
use Kraite\Core\Models\ExchangeSymbol;
use StepDispatcher\Models\Step;

/**
 * TouchTaapiDataForExchangeSymbolsJob
 *
 * Parent lifecycle job that creates child steps to touch TAAPI and check data availability
 * for Binance exchange symbols that haven't been checked yet.
 *
 * This job queries Binance exchange symbols where api_statuses->taapi_verified is false.
 * TAAPI only supports Binance data, so we only verify Binance symbols.
 * For each symbol, it creates a child step that makes a simple candle API call
 * to check if TAAPI has data for that symbol.
 */
final class TouchTaapiDataForExchangeSymbolsJob extends BaseQueueableJob
{
    public function relatable()
    {
        return null;
    }

    public function compute()
    {
        // Get Binance exchange symbols that:
        // 1. Don't have api_statuses->taapi_verified set to true yet
        // 2. Belong to Binance (TAAPI only supports Binance data)
        $symbolsToVerify = ExchangeSymbol::query()
            ->where(static function ($query) {
                $query->whereNull('api_statuses->taapi_verified')
                    ->orWhere('api_statuses->taapi_verified', false);
            })
            ->whereHas('apiSystem', static function ($query) {
                $query->where('canonical', 'binance');
            })
            ->get();

        if ($symbolsToVerify->isEmpty()) {
            // No children to create - clear child_block_uuid so StepDispatcher
            // can mark this step as complete (otherwise it waits for non-existent children)
            $this->step->update(['child_block_uuid' => null]);

            // Count how many symbols already have TAAPI data verified
            $alreadyVerifiedCount = ExchangeSymbol::where('api_statuses->taapi_verified', true)->count();

            return [
                'symbols_to_verify' => 0,
                'already_verified' => $alreadyVerifiedCount,
                'steps_created' => 0,
                'message' => $alreadyVerifiedCount > 0
                    ? "No new symbols to verify ({$alreadyVerifiedCount} already have TAAPI data verified)"
                    : 'No exchange symbols found that need TAAPI verification',
            ];
        }

        // Create a child step for each symbol to verify.
        // Child steps use $this->uuid() (parent's child_block_uuid) as their block_uuid.
        foreach ($symbolsToVerify as $exchangeSymbol) {
            Step::create([
                'class' => TouchTaapiDataForExchangeSymbolJob::class,
                'arguments' => [
                    'exchangeSymbolId' => $exchangeSymbol->id,
                ],
                'block_uuid' => $this->uuid(),
                'index' => 1,
            ]);
        }

        return [
            'symbols_to_verify' => $symbolsToVerify->count(),
            'steps_created' => $symbolsToVerify->count(),
            'message' => "TAAPI verification steps created for {$symbolsToVerify->count()} symbols",
        ];
    }
}
