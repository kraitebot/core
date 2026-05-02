<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Order\Bitget;

use Kraite\Core\Exceptions\NonNotifiableException;
use Kraite\Core\Jobs\Atomic\Order\CalculateWapAndModifyProfitOrderJob as BaseCalculateWapAndModifyProfitOrderJob;
use Kraite\Core\Models\Order;
use Kraite\Core\Support\Math;
use Kraite\Core\Support\Proxies\ApiDataMapperProxy;
use RuntimeException;
use Throwable;

/**
 * CalculateWapAndModifyProfitOrderJob (Atomic) - Bitget
 *
 * Bitget-specific WAP recalculation. Diverges from the Binance base in
 * two ways:
 *
 *   1. Bitget pos_profit / pos_loss orders are attached to the position
 *      and cannot be modified through any per-order endpoint. The
 *      `modify-tpsl-order` endpoint returns HTTP 400 / code 400172
 *      ("trigger price cannot be empty") for these orders across every
 *      field-name combination tried (verified live 2026-04-26 via
 *      tinker, confirmed again 2026-04-29 on production position #792).
 *      The only proven write path is `place-pos-tpsl`, which atomically
 *      overwrites BOTH legs while preserving the existing plan-order IDs.
 *
 *   2. `place-pos-tpsl` is intrinsically a paired call. Modifying just
 *      the TP requires reading the sibling SL leg's current price and
 *      sending it through unchanged. Same pattern as
 *      `Bitget\ModifyAlgoOrderJob` (drift-correction flow).
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
     * Calculate WAP and rewrite the position's TP via place-pos-tpsl.
     *
     * @return array<string, mixed>
     */
    public function computeApiable(): array
    {
        $scale = 8;
        $account = $this->position->account;

        $positions = \Kraite\Core\Models\ApiSnapshot::getFrom($account, 'account-positions');

        $positionKey = $this->buildPositionKey();

        $positionFromExchange = null;
        if (is_array($positions)) {
            if (array_key_exists($positionKey, $positions)) {
                $positionFromExchange = $positions[$positionKey];
            } else {
                $symbolKey = $this->position->parsed_trading_pair;
                if (array_key_exists($symbolKey, $positions)) {
                    $positionFromExchange = $positions[$symbolKey];
                }
            }
        }

        if ($positionFromExchange === null) {
            throw new NonNotifiableException(
                "Position {$positionKey} not found in account-positions snapshot — ".
                'likely closed by concurrent workflow or externally. Aborting WAP.'
            );
        }

        $this->breakEvenPrice = (string) ($positionFromExchange['breakEvenPrice'] ?? '0');
        $rawQty = (string) ($positionFromExchange['positionAmt']
            ?? $positionFromExchange['size']
            ?? $positionFromExchange['qty']
            ?? '0');

        if (Math::lte($this->breakEvenPrice, '0')) {
            throw new RuntimeException(
                "Invalid breakEvenPrice={$this->breakEvenPrice} for position {$positionKey}. ".
                'Cannot calculate WAP.'
            );
        }

        if (Math::equal($rawQty, '0')) {
            throw new NonNotifiableException(
                "Zero position quantity on exchange for {$positionKey} — ".
                'position likely closed mid-WAP. Aborting WAP.'
            );
        }

        $this->positionQty = Math::lt($rawQty, '0')
            ? Math::mul($rawQty, '-1', $scale)
            : $rawQty;

        // Consistency gate — same logic as the base class. Bitget's
        // breakEvenPrice is just as vulnerable to the "exchange hasn't yet
        // absorbed our triggering LIMIT fill" race as Binance's. Without
        // this, the TP would be computed against a stale breakeven.
        $expectedQty = (string) $this->position->orders()
            ->whereIn('type', ['MARKET', 'LIMIT'])
            ->where('status', 'FILLED')
            ->sum('quantity');

        if (Math::lt($this->positionQty, $expectedQty, $scale)) {
            throw new RuntimeException(sprintf(
                'breakEvenPrice snapshot lags the local fill ledger for position #%d: exchange qty=%s, local expected=%s. Exchange has not yet committed the fresh LIMIT fill. Retry.',
                $this->position->id,
                $this->positionQty,
                $expectedQty
            ));
        }

        $profitPct = (string) $this->position->profit_percentage;
        $fraction = Math::div($profitPct, '100', $scale);

        $isLong = mb_strtoupper((string) $this->position->direction) === 'LONG';
        $multiplier = $isLong
            ? Math::add('1', $fraction, $scale)
            : Math::sub('1', $fraction, $scale);

        $target = Math::mul($this->breakEvenPrice, $multiplier, $scale);

        $formattedPrice = api_format_price($target, $this->position->exchangeSymbol);
        $formattedQty = api_format_quantity($this->positionQty, $this->position->exchangeSymbol);

        $oldQty = (string) ($this->profitOrder->quantity ?? '0');
        $oldPrice = (string) ($this->profitOrder->price ?? '0');

        $this->intendedPrice = $formattedPrice;
        $this->intendedQty = $formattedQty;

        // Sibling SL leg is required: place-pos-tpsl is atomic on both
        // legs, so even when only the TP is changing the SL price must
        // travel through unchanged. Without it, the call would erase
        // the SL on the position.
        $sibling = $this->findSiblingStopLossOrder();
        if ($sibling === null) {
            throw new RuntimeException(sprintf(
                'Cannot apply WAP for Bitget position #%d: sibling STOP-MARKET leg not found. place-pos-tpsl is atomic on both legs and the SL price is required.',
                $this->position->id
            ));
        }

        $slPrice = (string) $sibling->price;

        $mapper = new ApiDataMapperProxy($account->apiSystem->canonical);
        $properties = $mapper->preparePlacePosTpslProperties(
            $this->position,
            $formattedPrice,
            $slPrice,
        );
        $properties->set('account', $account);

        try {
            $response = $account->withApi()->placePosTpsl($properties);
            $result = $mapper->resolvePlacePosTpslResponse($response);

            if (! ($result['success'] ?? false)) {
                throw new RuntimeException(
                    'Bitget place-pos-tpsl rejected: '.json_encode($result['_raw'] ?? [])
                );
            }
        } catch (Throwable $e) {
            throw new RuntimeException(sprintf(
                'placePosTpsl failed for profit order #%d (intended TP price=%s qty=%s; sibling SL price=%s; current TP DB: price=%s qty=%s status=%s). Original: %s',
                $this->profitOrder->id,
                $formattedPrice,
                $formattedQty,
                $slPrice,
                $this->profitOrder->price,
                $this->profitOrder->quantity,
                $this->profitOrder->status,
                $e->getMessage()
            ), 0, $e);
        }

        // place-pos-tpsl preserves the existing plan-order ID per Bitget's
        // contract (verified 2026-04-26 against FETUSDT pos 421), so we
        // can mirror the new price and quantity onto the local profit
        // order row directly without re-querying the position. The
        // quantity reflects the post-fill position size — Bitget's
        // position-level TP automatically tracks the position size, but
        // mirroring it locally keeps downstream calculations aligned.
        $this->profitOrder->updateSaving([
            'price' => $formattedPrice,
            'quantity' => $formattedQty,
        ]);

        // Operator notification — same surface the Binance base class
        // fires (cache-key throttled per position, mail + pushover).
        // Without this call the Bitget WAP path completes silently:
        // TP repriced, was_waped tagged, dashboard correct, but no
        // notification reaches the operator. Production trigger
        // 2026-05-02: ARKMUSDT WAPs fired on both Binance + Bitget,
        // only the Binance one notified.
        $this->dispatchWapAppliedNotification($oldPrice, $oldQty);

        return [
            'position_id' => $this->position->id,
            'order_id' => $this->profitOrder->id,
            'trading_pair' => $this->position->parsed_trading_pair,
            'direction' => $this->position->direction,
            'break_even_price' => $this->breakEvenPrice,
            'profit_percentage' => $profitPct,
            'old_price' => $oldPrice,
            'new_price' => $formattedPrice,
            'old_quantity' => $oldQty,
            'new_quantity' => $formattedQty,
            'sibling_sl_price' => $slPrice,
            'message' => 'WAP applied via Bitget place-pos-tpsl (atomic TP+SL overwrite)',
        ];
    }

    /**
     * Locate the position's STOP-MARKET algo leg so its current price
     * can be carried through the place-pos-tpsl call unchanged.
     *
     * Mirrors the helper on Bitget\ModifyAlgoOrderJob — same contract,
     * same expectations.
     */
    public function findSiblingStopLossOrder(): ?Order
    {
        return $this->position->orders()
            ->where('type', 'STOP-MARKET')
            ->where('is_algo', true)
            ->first();
    }
}
