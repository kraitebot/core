<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Position;

use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Trading\Engine;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Math;
use Kraite\Core\Support\Proxies\ApiDataMapperProxy;
use RuntimeException;
use Throwable;

/**
 * VerifyOrderNotionalForMarketOrderJob (Atomic)
 *
 * Fetches current mark price from exchange and validates
 * that the market order notional meets minimum requirements.
 *
 * Must run AFTER PreparePositionDataJob (margin, leverage, total_limit_orders must be set).
 * Must run BEFORE PlaceMarketOrderJob (sets mark_price on exchange symbol).
 *
 * Flow:
 * 1. Fetch mark price from exchange API
 * 2. Update exchange symbol with latest mark_price
 * 3. Calculate market order notional using divider formula
 * 4. Validate notional meets symbol minimum
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
        $mapper = new ApiDataMapperProxy($canonical);
        $properties = $mapper->prepareQueryMarkPriceProperties($exchangeSymbol);

        $response = Account::admin($canonical)->withApi()->getMarkPrice($properties);

        $markPrice = $mapper->resolveQueryMarkPriceResponse($response);

        if (! $markPrice || ! is_numeric($markPrice)) {
            throw new RuntimeException("Failed to fetch mark price for {$exchangeSymbol->parsed_trading_pair}");
        }

        // 2. Update exchange symbol with mark price (used by PlaceMarketOrderJob)
        $exchangeSymbol->updateSaving(['mark_price' => $markPrice]);

        // 3. Calculate market order notional using divider formula
        // divider = 2^(totalLimitOrders + 1) e.g., 4 limits = 32
        $divider = get_market_order_amount_divider($this->position->total_limit_orders);
        $margin = (string) $this->position->margin;
        $leverage = (string) $this->position->leverage;
        $notional = Math::div(Math::mul($margin, $leverage), (string) $divider);

        // 4. Calculate market order quantity using trait method (respects min notional)
        $marketOrderQuantity = $exchangeSymbol->getQuantityForAmount($notional, respectMinNotional: true);

        $effectiveMinNotional = Engine::getEffectiveMinNotional($exchangeSymbol);

        if ($marketOrderQuantity === '0') {
            throw new RuntimeException(
                "Order size ({$notional}) results in unusable quantity (fails minimum notional of {$effectiveMinNotional})"
            );
        }

        // 5. Get actual notional using trait method
        $marketOrderNotional = $exchangeSymbol->getAmountForQuantity($marketOrderQuantity);

        // 6. Verify notional meets exchange-specific minimum (handles KuCoin differences)
        if (! Engine::meetsMinNotional($exchangeSymbol, $marketOrderNotional)) {
            throw new RuntimeException(
                "Market order notional ({$marketOrderNotional}) below minimum ({$effectiveMinNotional})"
            );
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
}
