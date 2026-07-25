<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Position;

use Illuminate\Support\Facades\Log;
use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Math;
use Kraite\Core\Trading\Exchange\Exchange;
use Kraite\Core\Trading\Kraite;
use RuntimeException;
use Throwable;

/**
 * VerifyOrderNotionalForMarketOrderJob (Atomic)
 *
 * Fetches current mark price from exchange and validates that the FULL trade
 * plan (market order + DCA limit ladder) is feasible before any order is
 * placed on the exchange.
 *
 * Must run AFTER PreparePositionDataJob (margin, leverage, total_limit_orders must be set).
 * Must run BEFORE PlaceMarketOrderJob (stores a fresh mark-price snapshot).
 *
 * Flow:
 * 1. Fetch mark price from exchange API
 * 2. Store the latest mark price in the sidecar
 * 3. Calculate market order notional using divider formula
 * 4. Validate market-order notional meets symbol minimum
 * 5. Simulate the full limit ladder against mark_price + market_order_quantity
 *    and reject if any rung would violate min_notional. Catching this HERE is
 *    what prevents the "market filled, ladder rejected, cancel workflow eats
 *    a realized loss" failure mode (observed on low-price tokens like USELESS
 *    where the chained quantities quantize down below min_notional).
 */
final class VerifyOrderNotionalForMarketOrderJob extends BaseApiableJob
{
    public Position $position;

    public function __construct(int $positionId)
    {
        $this->position = Position::findOrFail($positionId);
    }

    public function assignExceptionHandler(): void
    {
        $this->exceptionHandler = BaseExceptionHandler::make(
            $this->position->account->apiSystem->canonical
        )->withAccount($this->position->account);
    }

    public function relatable()
    {
        return $this->position;
    }

    /**
     * Verify position has required data from PreparePositionDataJob.
     */
    public function startOrFail(): bool
    {
        // Margin must be set (set by PreparePositionDataJob)
        if ($this->position->margin === null) {
            return false;
        }

        // Leverage must be set
        if ($this->position->leverage === null) {
            return false;
        }

        // Total limit orders must be set
        if ($this->position->total_limit_orders === null) {
            return false;
        }

        return true;
    }

    public function computeApiable()
    {
        $exchangeSymbol = $this->position->exchangeSymbol;
        $canonical = $this->position->account->apiSystem->canonical;

        // 1. Fetch mark price from exchange (using admin account for public API)
        $mapper = Exchange::forCanonical($canonical)->mapper();
        $properties = $mapper->prepareQueryMarkPriceProperties($exchangeSymbol);

        $response = Account::admin($canonical)->withApi()->getMarkPrice($properties);

        $markPrice = $mapper->resolveQueryMarkPriceResponse($response);

        if (! $markPrice || ! is_numeric($markPrice)) {
            throw new RuntimeException("Failed to fetch mark price for {$exchangeSymbol->parsed_trading_pair}");
        }

        // 2. Store the REST snapshot in the sidecar read by all price computations.
        $exchangeSymbol->storeMarkPriceSnapshot($markPrice);

        // 3. Calculate market order notional using divider formula
        // divider = 2^(totalLimitOrders + 1) e.g., 4 limits = 32
        $divider = get_market_order_amount_divider($this->position->total_limit_orders);
        $margin = (string) $this->position->margin;
        $leverage = (string) $this->position->leverage;
        $notional = Math::div(Math::mul($margin, $leverage), (string) $divider);

        // 4. Calculate market order quantity using trait method (respects min notional)
        $marketOrderQuantity = $exchangeSymbol->getQuantityForAmount($notional, respectMinNotional: true);

        $effectiveMinNotional = Kraite::getEffectiveMinNotional($exchangeSymbol);

        if ($marketOrderQuantity === '0') {
            $this->logFailureContext($exchangeSymbol, $markPrice, $divider, $margin, $leverage, $notional, $marketOrderQuantity, null, 'unusable_quantity');

            throw new RuntimeException(
                "Order size ({$notional}) results in unusable quantity (fails minimum notional of {$effectiveMinNotional})"
            );
        }

        // 5. Get actual notional using trait method
        $marketOrderNotional = $exchangeSymbol->getAmountForQuantity($marketOrderQuantity);

        // 6. Verify notional meets exchange-specific minimum (handles KuCoin differences)
        if (! Kraite::meetsMinNotional($exchangeSymbol, $marketOrderNotional)) {
            $this->logFailureContext($exchangeSymbol, $markPrice, $divider, $margin, $leverage, $notional, $marketOrderQuantity, $marketOrderNotional, 'market_notional_below_minimum');

            throw new RuntimeException(
                "Market order notional ({$marketOrderNotional}) below minimum ({$effectiveMinNotional})"
            );
        }

        // 7. Ladder-feasibility pre-gate: running the simulation here (BEFORE
        // PlaceMarketOrderJob) is the whole point — if it throws at the
        // downstream DispatchLimitOrdersJob instead, we've already filled the
        // market and have to unwind an orphaned entry at a realized loss.
        try {
            Kraite::calculateLimitOrdersData(
                totalLimitOrders: $this->position->total_limit_orders,
                direction: (string) $this->position->direction,
                referencePrice: $markPrice,
                marketOrderQty: $marketOrderQuantity,
                exchangeSymbol: $exchangeSymbol,
                limitQuantityMultipliers: $exchangeSymbol->limit_quantity_multipliers,
            );
        } catch (Throwable $ladderError) {
            // Ladder simulation rejected the projected chain — most
            // commonly a rung-notional breach. Capture the full input
            // vector that produced the rejection so we can forensically
            // reconstruct anomalies like TAKE #146 (where the same
            // inputs reproduced minutes later produced a healthy
            // ladder — suggesting a transient calc race we couldn't
            // pin from logs alone).
            $this->logFailureContext($exchangeSymbol, $markPrice, $divider, $margin, $leverage, $notional, $marketOrderQuantity, $marketOrderNotional, 'ladder_infeasible', $ladderError->getMessage());

            throw $ladderError;
        }

        return [
            'position_id' => $this->position->id,
            'symbol' => $exchangeSymbol->parsed_trading_pair,
            'mark_price' => $markPrice,
            'divider' => $divider,
            'margin' => $this->position->margin,
            'leverage' => $this->position->leverage,
            'notional' => $notional,
            'market_order_quantity' => $marketOrderQuantity,
            'market_order_notional' => $marketOrderNotional,
            'message' => "Notional validated: qty={$marketOrderQuantity}, notional={$marketOrderNotional}",
        ];
    }

    public function resolveException(Throwable $e): void
    {
        $this->position->updateSaving([
            'error_message' => $e->getMessage(),
        ]);
    }

    /**
     * Write the full input vector to the jobs log channel whenever a
     * pre-gate check fails. Lets us forensically reconstruct transient
     * anomalies (e.g. TAKE #146 on 2026-04-23 where identical inputs
     * reproduced minutes later produced a healthy ladder). The step's
     * error_message only captures the final `RuntimeException` string;
     * this log line captures what went INTO the math that produced it.
     */
    private function logFailureContext(
        ExchangeSymbol $exchangeSymbol,
        string $markPrice,
        int $divider,
        string $margin,
        string $leverage,
        string $notional,
        string $marketOrderQuantity,
        ?string $marketOrderNotional,
        string $reason,
        ?string $ladderError = null,
    ): void {
        Log::channel('jobs')->warning('[VERIFY-NOTIONAL] Pre-gate rejected a trade — capturing inputs', [
            'reason' => $reason,
            'position_id' => $this->position->id,
            'symbol' => $exchangeSymbol->parsed_trading_pair,
            'direction' => $this->position->direction,
            'margin' => $margin,
            'leverage' => $leverage,
            'divider' => $divider,
            'notional' => $notional,
            'mark_price_fetched' => $markPrice,
            'mark_price_in_memory' => $exchangeSymbol->mark_price,
            'quantity_precision' => $exchangeSymbol->quantity_precision,
            'tick_size' => $exchangeSymbol->tick_size,
            'min_notional' => $exchangeSymbol->min_notional,
            'percentage_gap_long' => $exchangeSymbol->percentage_gap_long,
            'percentage_gap_short' => $exchangeSymbol->percentage_gap_short,
            'total_limit_orders' => $exchangeSymbol->total_limit_orders,
            'limit_quantity_multipliers' => $exchangeSymbol->limit_quantity_multipliers,
            'market_order_quantity' => $marketOrderQuantity,
            'market_order_notional' => $marketOrderNotional,
            'ladder_error' => $ladderError,
        ]);
    }
}
