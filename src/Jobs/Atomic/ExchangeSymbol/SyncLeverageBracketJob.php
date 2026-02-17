<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\ExchangeSymbol;

use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ExchangeSymbol;

/**
 * SyncLeverageBracketJob (Atomic)
 *
 * Fetches leverage brackets for a single exchange symbol.
 * Used for exchanges that require per-symbol API calls (Bybit, KuCoin).
 */
class SyncLeverageBracketJob extends BaseApiableJob
{
    public ExchangeSymbol $exchangeSymbol;

    public function __construct(int $exchangeSymbolId)
    {
        $this->exchangeSymbol = ExchangeSymbol::with('apiSystem')->findOrFail($exchangeSymbolId);
    }

    public function relatable()
    {
        return $this->exchangeSymbol;
    }

    public function assignExceptionHandler(): void
    {
        $canonical = $this->exchangeSymbol->apiSystem->canonical;
        $this->exceptionHandler = BaseExceptionHandler::make($canonical)
            ->withAccount(Account::admin($canonical));
    }

    public function startOrFail()
    {
        return $this->exchangeSymbol->apiSystem->is_exchange;
    }

    public function computeApiable()
    {
        $apiSystem = $this->exchangeSymbol->apiSystem;
        $symbol = $this->exchangeSymbol->asset;

        // Fetch leverage brackets for this specific symbol
        $apiResponse = $apiSystem->apiQueryLeverageBracketsDataForSymbol($symbol);
        $bracketsData = $apiResponse->result;

        // Response may be array of symbols (filtered) or single symbol data
        $symbolData = null;

        // Handle array response (e.g., Bybit returns array even for single symbol)
        if (isset($bracketsData[0])) {
            // Find matching symbol in response
            foreach ($bracketsData as $data) {
                if (($data['symbol'] ?? null) === $symbol) {
                    $symbolData = $data;

                    break;
                }
            }
        } elseif (isset($bracketsData[$symbol])) {
            // Handle keyed by symbol response
            $symbolData = $bracketsData[$symbol];
        } elseif (isset($bracketsData['symbol']) && $bracketsData['symbol'] === $symbol) {
            // Handle single symbol response
            $symbolData = $bracketsData;
        }

        if (! $symbolData) {
            return [
                'exchange' => $apiSystem->canonical,
                'symbol' => $symbol,
                'status' => 'not_found',
            ];
        }

        // Extract brackets from response
        $brackets = $symbolData['brackets'] ?? null;

        // Handle exchanges that return maxLeverage without brackets structure
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

        // Handle KuCoin's levels structure
        if ($brackets === null && isset($symbolData['levels'])) {
            $brackets = collect($symbolData['levels'])
                ->map(function (array $level): array {
                    return [
                        'bracket' => $level['level'] ?? 0,
                        'initialLeverage' => $level['maxLeverage'] ?? 0,
                        'notionalCap' => $level['maxRiskLimit'] ?? null,
                        'notionalFloor' => $level['minRiskLimit'] ?? 0,
                        'maintMarginRatio' => $level['maintainMargin'] ?? null,
                    ];
                })
                ->toArray();
        }

        if ($brackets) {
            $this->exchangeSymbol->update(['leverage_brackets' => $brackets]);

            return [
                'exchange' => $apiSystem->canonical,
                'symbol' => $symbol,
                'status' => 'updated',
                'brackets_count' => count($brackets),
            ];
        }

        return [
            'exchange' => $apiSystem->canonical,
            'symbol' => $symbol,
            'status' => 'no_brackets',
        ];
    }
}
