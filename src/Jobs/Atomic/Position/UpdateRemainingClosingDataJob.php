<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Position;

use Illuminate\Support\Facades\DB;
use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Notifications\AlertNotification;
use Kraite\Core\Support\Math;

/**
 * UpdateRemainingClosingDataJob (Atomic)
 *
 * Updates closing data for a position after it has been closed:
 * - Sets closing_price from trade data (if available)
 * - Sets was_fast_traded flag based on position duration
 * - Sends high-profit notification if filled limit orders >= threshold
 * - Updates all orders' reference_status to match their current status
 */
final class UpdateRemainingClosingDataJob extends BaseApiableJob
{
    public Position $position;

    public function __construct(int $positionId)
    {
        $this->position = Position::findOrFail($positionId);
    }

    public function assignExceptionHandler(): void
    {
        $canonical = $this->position->account->apiSystem->canonical;
        $this->exceptionHandler = BaseExceptionHandler::make($canonical)
            ->withAccount($this->position->account);
    }

    public function relatable()
    {
        return $this->position;
    }

    public function computeApiable()
    {
        $position = $this->position;
        $account = $position->account;
        $closingPrice = null;
        $wasFastTraded = false;
        $highProfitNotificationSent = false;

        // 1. Primary: pull the closing price from the exchange's trade
        // history. Works for all close flavours — our own workflow AND a
        // manual close done directly on the exchange — because every
        // reducing fill lands in userTrades regardless of the order that
        // produced it. Previously this path was gated behind a non-null
        // `profitOrder()`, which excludes CANCELLED/EXPIRED rows and so
        // returned null whenever the user manual-closed (which leaves the
        // TP as EXPIRED on Binance), losing the closing_price entirely.
        try {
            $tradesResponse = $position->apiQueryTokenTrades();

            if ($tradesResponse->result) {
                $trades = is_array($tradesResponse->result) ? $tradesResponse->result : [];
                $closingPrice = $this->extractClosingPriceFromTrades($trades, (string) $position->direction);
            }
        } catch (\Throwable $e) {
            // Log but don't fail - closing price is nice to have
            info("Failed to get closing price for position {$position->id}: ".$e->getMessage());
        }

        // Fallback: TP filled naturally. Use its own price.
        if ($closingPrice === null) {
            $profitOrder = $position->profitOrder();
            if ($profitOrder instanceof Order && $profitOrder->status === 'FILLED') {
                $closingPrice = $profitOrder->price;
            }
        }

        // Update closing_price if available
        if ($closingPrice !== null) {
            $position->updateSaving(['closing_price' => $closingPrice]);
        }

        // 2. Check if fast traded
        if ($position->opened_at !== null) {
            $tradeConfig = $account->tradeConfiguration;
            $fastDurationSeconds = $tradeConfig?->fast_trade_position_duration_seconds ?? 300; // Default 5 min

            $durationSeconds = $position->opened_at->diffInSeconds(now());

            if ($durationSeconds < $fastDurationSeconds) {
                $wasFastTraded = true;
                $position->updateSaving(['was_fast_traded' => true]);
            }
        }

        // 3. High-profit notification
        $filledLimitCount = $position->totalLimitOrdersFilled();
        $notifyThreshold = $account->total_limit_orders_filled_to_notify ?? 0;

        if ($notifyThreshold > 0 && $filledLimitCount >= $notifyThreshold) {
            $this->sendHighProfitNotification($position, $filledLimitCount);
            $highProfitNotificationSent = true;
        }

        // 4. Sync reference_status = status for all orders on this position.
        // Single UPDATE inside a transaction — prior version did N individual
        // updateSaving() calls in a foreach, which could leave mixed state if
        // the job failed mid-loop and re-trigger the observer on next sync.
        DB::transaction(function () use ($position) {
            Order::where('position_id', $position->id)
                ->update(['reference_status' => DB::raw('status')]);
        });

        return [
            'position_id' => $position->id,
            'closing_price' => $closingPrice,
            'was_fast_traded' => $wasFastTraded,
            'filled_limit_count' => $filledLimitCount,
            'high_profit_notification_sent' => $highProfitNotificationSent,
            'message' => 'Closing data updated',
        ];
    }

    /**
     * Pick the closing trade's price from a user-trades response.
     *
     * A closing fill is the reducing leg of the position: SELL for LONG,
     * BUY for SHORT, optionally tagged with the matching positionSide on
     * hedge-mode exchanges (Binance). We scan newest-first and return the
     * most recent match so partial/multi-step closes resolve to the final
     * reducing fill. If no trade carries side/positionSide metadata (some
     * exchanges omit it), fall back to the last trade in the list — it's
     * the most recent fill for the symbol, which is the close we just ran.
     */
    private function extractClosingPriceFromTrades(array $trades, string $direction): ?string
    {
        if (empty($trades)) {
            return null;
        }

        $direction = mb_strtoupper($direction);
        $closeSide = $direction === 'LONG' ? 'SELL' : 'BUY';

        foreach (array_reverse($trades) as $trade) {
            $side = mb_strtoupper((string) ($trade['side'] ?? ''));
            $positionSide = mb_strtoupper((string) ($trade['positionSide'] ?? ''));

            $sideMatches = $side === $closeSide;
            $positionSideMatches = $positionSide === '' || $positionSide === $direction;

            if ($sideMatches && $positionSideMatches) {
                $price = $trade['price'] ?? $trade['execPrice'] ?? null;

                if (Math::isPositive($price)) {
                    return (string) $price;
                }
            }
        }

        // Exchange didn't tag side/positionSide — use most recent trade.
        $lastTrade = end($trades);
        $price = $lastTrade['price'] ?? $lastTrade['execPrice'] ?? null;

        if (Math::isPositive($price)) {
            return (string) $price;
        }

        return null;
    }

    /**
     * Send high-profit notification to the account owner.
     */
    private function sendHighProfitNotification(Position $position, int $filledLimitCount): void
    {
        $user = $position->account->user;

        if (! $user || ! $user->is_active) {
            return;
        }

        $message = sprintf(
            '🎉 High profit position closed! %s filled %d limit orders.',
            $position->parsed_trading_pair,
            $filledLimitCount
        );

        $user->notify(new AlertNotification(
            message: $message,
            title: 'High Profit Position',
            canonical: 'high_profit_position_closed',
            deliveryGroup: 'default'
        ));
    }
}
