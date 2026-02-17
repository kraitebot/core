<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Order\Bitget;

use Kraite\Core\Jobs\Atomic\Order\CalculateWapAndModifyProfitOrderJob as BaseCalculateWapAndModifyProfitOrderJob;
use Kraite\Core\Support\Math;
use RuntimeException;

/**
 * CalculateWapAndModifyProfitOrderJob (Atomic) - Bitget
 *
 * Bitget-specific implementation for WAP calculation and profit order modification.
 *
 * Key difference from Binance:
 * - Uses apiModifyTpsl() instead of apiModify()
 * - Bitget position-level TP/SL can only modify the trigger price, not quantity
 * - The position quantity is already updated from the exchange snapshot via $this->positionQty
 *
 * Bitget Futures position response includes:
 * - symbol: e.g. "BTCUSDT"
 * - size: position size (absolute value)
 * - breakEvenPrice: weighted average entry price
 * - holdSide: "long" or "short"
 */
final class CalculateWapAndModifyProfitOrderJob extends BaseCalculateWapAndModifyProfitOrderJob
{
    /**
     * Calculate WAP and modify profit order using apiModifyTpsl().
     *
     * @return array<string, mixed>
     */
    public function computeApiable(): array
    {
        $scale = 8;

        // 1) Read the latest account-positions snapshot
        $positions = \Kraite\Core\Models\ApiSnapshot::getFrom($this->position->account, 'account-positions');

        // 2) Build position key and find in snapshot
        // BitGet format: "BTCUSDT:LONG" or "BTCUSDT:SHORT"
        $positionKey = $this->buildPositionKey();

        // Try keyed lookup first
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

        // Absolute quantity (SHORT may arrive negative on some exchanges)
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

        // 5) Format price for exchange
        $formattedPrice = api_format_price($target, $this->position->exchangeSymbol);

        // 6) Capture old values for logging
        $oldQty = (string) ($this->profitOrder->quantity ?? '0');
        $oldPrice = (string) ($this->profitOrder->price ?? '0');

        // 7) Modify on exchange using apiModifyTpsl() (price only)
        // BitGet position-level TP/SL can only modify trigger price, not quantity
        $this->profitOrder->apiModifyTpsl($formattedPrice);
        $this->profitOrder->apiSync();

        // Update quantity locally to match exchange position
        // (apiModifyTpsl doesn't modify quantity, but position quantity may have changed)
        $formattedQty = api_format_quantity($this->positionQty, $this->position->exchangeSymbol);
        $this->profitOrder->updateSaving([
            'quantity' => $formattedQty,
        ]);

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
            'new_quantity' => $formattedQty,
            'message' => 'WAP calculated and profit order modified via apiModifyTpsl',
        ];
    }
}
