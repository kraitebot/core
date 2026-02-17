<?php

declare(strict_types=1);

namespace Kraite\Core\_Jobs\Models\ExchangeSymbol;

use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Indicator;
use Kraite\Core\Models\IndicatorHistory;

/**
 * CleanupIndicatorHistoriesJob
 *
 * Deletes all conclude-indicators indicator histories for an exchange symbol.
 * This runs after the e2e indicator conclusion analysis is complete,
 * ensuring fresh data on the next analysis cycle.
 *
 * This job ALWAYS runs as the final step in the workflow, regardless
 * of whether the previous steps succeeded or failed.
 */
final class CleanupIndicatorHistoriesJob extends BaseQueueableJob
{
    public int $exchangeSymbolId;

    public function __construct(int $exchangeSymbolId)
    {
        $this->exchangeSymbolId = $exchangeSymbolId;
    }

    public function relatable()
    {
        return ExchangeSymbol::find($this->exchangeSymbolId);
    }

    public function compute()
    {
        $exchangeSymbol = ExchangeSymbol::findOrFail($this->exchangeSymbolId);

        // Delete all indicator_histories entries for conclude-indicators indicators
        $refreshDataIndicatorIds = Indicator::where('type', 'conclude-indicators')->pluck('id');

        if ($refreshDataIndicatorIds->isEmpty()) {
            return ['response' => 'No conclude-indicators indicators found to clean up'];
        }

        $deletedCount = IndicatorHistory::query()
            ->where('exchange_symbol_id', $exchangeSymbol->id)
            ->whereIn('indicator_id', $refreshDataIndicatorIds)
            ->delete();

        return ['response' => "Cleaned up {$deletedCount} indicator histories for exchange symbol {$exchangeSymbol->id}"];
    }
}
