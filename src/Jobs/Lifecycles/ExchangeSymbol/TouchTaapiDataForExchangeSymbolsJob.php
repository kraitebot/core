<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\ExchangeSymbol;

use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Jobs\Models\ExchangeSymbol\TouchTaapiDataForExchangeSymbolJob;
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
            ->onActiveApiSystem()
            ->where(static function ($query) {
                $query->whereNull('api_statuses->taapi_verified')
                    ->orWhere('api_statuses->taapi_verified', false);
            })
            ->whereHas('apiSystem', static function ($query) {
                $query->canonical('binance');
            })
            ->get();

        if ($symbolsToVerify->isEmpty()) {
            // No children to create — DON'T elect to parent mode. Step
            // completes as orphan, no zombie. (See ~/steps-dispatcher/issue.md.)
            $alreadyVerifiedCount = ExchangeSymbol::onActiveApiSystem()
                ->where('api_statuses->taapi_verified', true)
                ->count();

            return [
                'symbols_to_verify' => 0,
                'already_verified' => $alreadyVerifiedCount,
                'steps_created' => 0,
                'message' => $alreadyVerifiedCount > 0
                    ? "No new symbols to verify ({$alreadyVerifiedCount} already have TAAPI data verified)"
                    : 'No exchange symbols found that need TAAPI verification',
            ];
        }

        $this->buildChildChainOnce(function (string $childBlockUuid) use ($symbolsToVerify): void {
            foreach ($symbolsToVerify as $exchangeSymbol) {
                Step::create([
                    'class' => TouchTaapiDataForExchangeSymbolJob::class,
                    'queue' => 'indicators',
                    'arguments' => [
                        'exchangeSymbolId' => $exchangeSymbol->id,
                    ],
                    'block_uuid' => $childBlockUuid,
                    'index' => 1,
                ]);
            }
        });

        return [
            'symbols_to_verify' => $symbolsToVerify->count(),
            'steps_created' => $symbolsToVerify->count(),
            'message' => "TAAPI verification steps created for {$symbolsToVerify->count()} symbols",
        ];
    }
}
