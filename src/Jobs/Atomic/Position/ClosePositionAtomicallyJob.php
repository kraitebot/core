<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Position;

use Carbon\Carbon;
use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\IndicatorHistory;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\User;
use Kraite\Core\Notifications\AlertNotification;
use Kraite\Core\Support\Math;

/**
 * ClosePositionAtomicallyJob (Atomic)
 *
 * Closes the position on exchange via market order.
 *
 * Features:
 * - Pump cooldown detection: If price spiked > threshold, sets tradeable_at cooldown
 * - Closes position using Position::apiClose()
 * - Returns filled limit order count for later notification handling
 */
final class ClosePositionAtomicallyJob extends BaseApiableJob
{
    public Position $position;

    public bool $verifyPrice;

    public function __construct(int $positionId, bool $verifyPrice = false)
    {
        $this->position = Position::findOrFail($positionId);
        $this->verifyPrice = $verifyPrice;
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
        $exchangeSymbol = $position->exchangeSymbol;
        $pumpCooldownTriggered = false;
        $cooldownDetails = [];

        // Pump cooldown check
        $spikeThreshold = $exchangeSymbol->disable_on_price_spike_percentage;
        $cooldownHours = $exchangeSymbol->price_spike_cooldown_hours;

        if ($spikeThreshold !== null && Math::gt((string) $spikeThreshold, '0')) {
            $currentPrice = $exchangeSymbol->mark_price ?? $exchangeSymbol->current_price;

            // Get 1D candle close price from IndicatorHistory
            $dailyIndicator = IndicatorHistory::where('exchange_symbol_id', $exchangeSymbol->id)
                ->where('timeframe', '1d')
                ->orderByDesc('timestamp')
                ->first();

            if ($dailyIndicator && $currentPrice) {
                $closePrice = $dailyIndicator->data['close'] ?? null;

                if ($closePrice && Math::gt((string) $closePrice, '0')) {
                    // Calculate price change percentage: |current - close| / close * 100
                    $diff = Math::subtract((string) $currentPrice, (string) $closePrice);
                    $absDiff = Math::lt($diff, '0') ? Math::multiply($diff, '-1') : $diff;
                    $changePercent = Math::multiply(Math::divide($absDiff, (string) $closePrice), '100');

                    if (Math::gte($changePercent, (string) $spikeThreshold)) {
                        // Price spike detected - set cooldown
                        $tradeableAt = now()->addHours($cooldownHours ?? 4);
                        $exchangeSymbol->updateSaving(['tradeable_at' => $tradeableAt]);

                        $pumpCooldownTriggered = true;
                        $cooldownDetails = [
                            'current_price' => $currentPrice,
                            'daily_close' => $closePrice,
                            'change_percent' => $changePercent,
                            'threshold' => $spikeThreshold,
                            'tradeable_at' => $tradeableAt->toDateTimeString(),
                        ];

                        // Notify admins about pump cooldown
                        $this->notifyPumpCooldown($exchangeSymbol->token, $changePercent, $tradeableAt);
                    }
                }
            }
        }

        // Check if position still exists on exchange before trying to close
        // (TP/SL fill may have already closed it)
        $accountPositions = $position->account->apiQueryPositions()->result ?? [];
        $symbol = mb_strtoupper($position->parsed_trading_pair);
        $direction = mb_strtoupper($position->direction);
        $positionKey = "{$symbol}:{$direction}";

        $positionExistsOnExchange = isset($accountPositions[$positionKey])
            && abs((float) ($accountPositions[$positionKey]['positionAmt'] ?? $accountPositions[$positionKey]['total'] ?? 0)) > 0.0001;

        if (! $positionExistsOnExchange) {
            // Position already closed on exchange (via TP/SL fill) - nothing to do
            return [
                'position_id' => $position->id,
                'symbol' => $position->parsed_trading_pair,
                'filled_limit_count' => $position->totalLimitOrdersFilled(),
                'pump_cooldown_triggered' => $pumpCooldownTriggered,
                'cooldown_details' => $cooldownDetails,
                'result' => ['already_closed' => true],
                'message' => 'Position already closed on exchange (via TP/SL fill)',
            ];
        }

        // Close position on exchange
        $apiResponse = $position->apiClose();

        // Count filled limit orders for notification (used by UpdateRemainingClosingDataJob)
        $filledLimitCount = $position->totalLimitOrdersFilled();

        return [
            'position_id' => $position->id,
            'symbol' => $position->parsed_trading_pair,
            'filled_limit_count' => $filledLimitCount,
            'pump_cooldown_triggered' => $pumpCooldownTriggered,
            'cooldown_details' => $cooldownDetails,
            'result' => $apiResponse->result,
            'message' => 'Position closed on exchange',
        ];
    }

    /**
     * Notify admins about pump cooldown trigger.
     */
    private function notifyPumpCooldown(string $token, string $changePercent, Carbon $tradeableAt): void
    {
        $message = sprintf(
            '⚠️ Pump cooldown triggered for %s. Price change: %s%%. Tradeable again at: %s',
            $token,
            $changePercent,
            $tradeableAt->format('Y-m-d H:i:s')
        );

        // Notify all admin users via exceptions delivery group
        User::query()
            ->where('is_admin', true)
            ->where('is_active', true)
            ->get()
            ->each(function (User $user) use ($message) {
                $user->notify(new AlertNotification(
                    message: $message,
                    title: 'Pump Cooldown Triggered',
                    canonical: 'pump_cooldown_triggered',
                    deliveryGroup: 'exceptions'
                ));
            });
    }
}
