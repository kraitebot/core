<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\ApiSystem;

use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;

/**
 * SyncLeverageBracketsJob (Atomic)
 *
 * Fetches leverage brackets from exchange API and updates exchange_symbols.
 * Used for exchanges that return all symbols in one API call (Binance).
 */
class SyncLeverageBracketsJob extends BaseApiableJob
{
    public ApiSystem $apiSystem;

    public function __construct(int $apiSystemId)
    {
        $this->apiSystem = ApiSystem::findOrFail($apiSystemId);
    }

    public function relatable()
    {
        return $this->apiSystem;
    }

    public function assignExceptionHandler(): void
    {
        $canonical = $this->apiSystem->canonical;
        $this->exceptionHandler = BaseExceptionHandler::make($canonical)
            ->withAccount(Account::admin($canonical));
    }

    public function startOrFail()
    {
        return $this->apiSystem->is_exchange;
    }

    public function computeApiable()
    {
        // Fetch leverage brackets from exchange
        $apiResponse = $this->apiSystem->apiQueryLeverageBracketsData();
        $bracketsData = $apiResponse->result;

        $updatedCount = 0;
        $skippedCount = 0;

        // Process based on exchange response format
        foreach ($bracketsData as $symbolData) {
            $exchangeSymbol = $symbolData['symbol'] ?? null;
            $brackets = $symbolData['brackets'] ?? null;

            // Some exchanges provide maxLeverage directly instead of brackets array
            if ($brackets === null && isset($symbolData['maxLeverage'])) {
                $brackets = [
                    [
                        'bracket' => 1,
                        'initialLeverage' => (int) $symbolData['maxLeverage'],
                        'notionalCap' => null,
                        'notionalFloor' => 0,
                        'maintMarginRatio' => null,
                    ],
                ];
            }

            if (! $exchangeSymbol || ! $brackets) {
                $skippedCount++;

                continue;
            }

            // Find and update the exchange symbol
            $symbol = ExchangeSymbol::where('api_system_id', $this->apiSystem->id)
                ->where('asset', $exchangeSymbol)
                ->first();

            if ($symbol) {
                $symbol->update(['leverage_brackets' => $brackets]);
                $updatedCount++;
            } else {
                $skippedCount++;
            }
        }

        return [
            'exchange' => $this->apiSystem->canonical,
            'updated' => $updatedCount,
            'skipped' => $skippedCount,
            'total_from_api' => count($bracketsData),
        ];
    }
}
