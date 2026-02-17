<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Order;

use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\ApiSnapshot;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Math;
use RuntimeException;
use Throwable;

/**
 * CalculateWapAndModifyProfitOrderJob (Atomic)
 *
 * Calculates the Weighted Average Price (WAP) from the exchange's breakEvenPrice
 * and modifies the PROFIT order accordingly.
 *
 * Calculation:
 * 1. Read positions from ApiSnapshot (account-positions)
 * 2. Extract breakEvenPrice and positionAmt for the position
 * 3. Calculate new TP price: breakEvenPrice * (1 ± profit_percentage/100)
 * 4. Modify the profit order via apiModify()
 * 5. Update reference values to prevent correction loop
 *
 * Guards:
 * - Position must be in 'waping' status
 * - Profit order must exist
 * - profit_percentage must be configured
 */
class CalculateWapAndModifyProfitOrderJob extends BaseApiableJob
{
    public Position $position;

    public ?Order $profitOrder = null;

    public ?string $breakEvenPrice = null;

    public ?string $positionQty = null;

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
     * Verify position is ready for WAP calculation.
     */
    public function startOrFail(): bool
    {
        // Position must be in 'waping' status (set by UpdatePositionStatusJob)
        if ($this->position->status !== 'waping') {
            return false;
        }

        // Must have a profit order to modify
        $this->profitOrder = $this->position->profitOrder();
        if ($this->profitOrder === null) {
            return false;
        }

        // Must have profit_percentage configured
        if ($this->position->profit_percentage === null) {
            return false;
        }

        return true;
    }

    public function computeApiable()
    {
        $scale = 8;

        // 1) Read the latest account-positions snapshot
        $positions = ApiSnapshot::getFrom($this->position->account, 'account-positions');

        // 2) Build position key and find in snapshot
        // Key format: "BTCUSDT:LONG" or "BTCUSDT:SHORT"
        $positionKey = $this->buildPositionKey();

        // Try keyed lookup first (for Binance format: symbol:direction)
        $positionFromExchange = null;
        if (is_array($positions)) {
            if (array_key_exists($positionKey, $positions)) {
                $positionFromExchange = $positions[$positionKey];
            } else {
                // Fallback: search by symbol (simpler format: just symbol key)
                $symbolKey = $this->position->parsed_trading_pair;
                if (array_key_exists($symbolKey, $positions)) {
                    $positionFromExchange = $positions[$symbolKey];
                }
            }
        }

        if ($positionFromExchange === null) {
            throw new RuntimeException(
                "Position {$positionKey} not found in account-positions snapshot. " .
                'Position may have been closed externally.'
            );
        }

        // 3) Extract breakEvenPrice and positionAmt
        $this->breakEvenPrice = (string) ($positionFromExchange['breakEvenPrice'] ?? '0');
        $rawQty = (string) ($positionFromExchange['positionAmt']
            ?? $positionFromExchange['size']
            ?? $positionFromExchange['qty']
            ?? '0');

        // Validate breakEvenPrice
        if (Math::lte($this->breakEvenPrice, '0')) {
            throw new RuntimeException(
                "Invalid breakEvenPrice={$this->breakEvenPrice} for position {$positionKey}. " .
                'Cannot calculate WAP.'
            );
        }

        // Validate quantity
        if (Math::equal($rawQty, '0')) {
            throw new RuntimeException(
                "Zero quantity from exchange for position {$positionKey}. " .
                'Cannot calculate WAP.'
            );
        }

        // Absolute quantity (SHORT arrives negative on Binance)
        $this->positionQty = Math::lt($rawQty, '0')
            ? Math::mul($rawQty, '-1', $scale)
            : $rawQty;

        // 4) Calculate target price
        $profitPct = (string) $this->position->profit_percentage;  // e.g. "0.350"
        $fraction = Math::div($profitPct, '100', $scale);          // -> "0.0035"

        $isLong = mb_strtoupper((string) $this->position->direction) === 'LONG';
        $multiplier = $isLong
            ? Math::add('1', $fraction, $scale)    // LONG: 1 + fraction
            : Math::sub('1', $fraction, $scale);   // SHORT: 1 - fraction

        $target = Math::mul($this->breakEvenPrice, $multiplier, $scale);

        // 5) Format price & quantity for exchange
        $formattedPrice = api_format_price($target, $this->position->exchangeSymbol);
        $formattedQty = api_format_quantity($this->positionQty, $this->position->exchangeSymbol);

        // 6) Capture old values for logging
        $oldQty = (string) ($this->profitOrder->quantity ?? '0');
        $oldPrice = (string) ($this->profitOrder->price ?? '0');

        // 7) Modify on exchange and sync
        $this->profitOrder->apiModify((float) $formattedQty, (float) $formattedPrice);
        $this->profitOrder->apiSync();

        return [
            'position_id' => $this->position->id,
            'order_id' => $this->profitOrder->id,
            'trading_pair' => $this->position->parsed_trading_pair,
            'direction' => $this->position->direction,
            'break_even_price' => $this->breakEvenPrice,
            'profit_percentage' => $profitPct,
            'old_price' => $oldPrice,
            'new_price' => $this->profitOrder->price,
            'old_quantity' => $oldQty,
            'new_quantity' => $this->profitOrder->quantity,
            'message' => 'WAP calculated and profit order modified',
        ];
    }

    /**
     * Verify the profit order was modified successfully.
     */
    public function doubleCheck(): bool
    {
        if ($this->profitOrder === null) {
            return false;
        }

        $this->profitOrder->refresh();

        // Profit order is valid if status is active
        return in_array($this->profitOrder->status, ['NEW', 'PARTIALLY_FILLED'], true);
    }

    /**
     * Update reference values to prevent correction loop.
     */
    public function complete(): void
    {
        if ($this->profitOrder === null) {
            return;
        }

        // CRITICAL: Update reference values to prevent OrderObserver from
        // detecting a modification and dispatching PrepareOrderCorrectionJob
        $this->profitOrder->updateSaving([
            'reference_quantity' => $this->profitOrder->quantity,
            'reference_price' => $this->profitOrder->price,
        ]);

        // Update position with WAP data
        $this->position->updateSaving([
            'quantity' => $this->profitOrder->quantity,
            'was_waped' => true,
            'waped_at' => now(),
        ]);
    }

    /**
     * Handle exceptions during WAP calculation.
     */
    public function resolveException(Throwable $e): void
    {
        $this->position->updateSaving([
            'error_message' => 'WAP calculation failed: ' . $e->getMessage(),
        ]);
    }

    /**
     * Build the position key for snapshot lookup.
     *
     * Format varies by exchange:
     * - Binance: "BTCUSDT:LONG" or "BTCUSDT:SHORT"
     * - Others: "BTCUSDT"
     */
    protected function buildPositionKey(): string
    {
        $symbol = $this->position->parsed_trading_pair;
        $direction = mb_strtoupper((string) $this->position->direction);

        return "{$symbol}:{$direction}";
    }
}
