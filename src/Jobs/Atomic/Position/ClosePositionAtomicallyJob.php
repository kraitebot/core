<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Position;

use Carbon\Carbon;
use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\IndicatorHistory;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Math;
use Kraite\Core\Support\NotificationService;
use Throwable;

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
                // The TAAPI `candle` indicator is queried with results=2, so
                // OHLCV fields arrive as arrays ordered oldest→newest ("most
                // recent value returned last"). Take the most recent close.
                // A single-result query would return a scalar, so both shapes
                // are normalised here before the numeric comparison below.
                $rawClose = $dailyIndicator->data['close'] ?? null;

                if (is_array($rawClose)) {
                    $closePrice = $rawClose[array_key_last($rawClose)] ?? null;
                } else {
                    $closePrice = $rawClose;
                }

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
                        $this->notifyPumpCooldown(
                            $exchangeSymbol,
                            $changePercent,
                            (string) $spikeThreshold,
                            $currentPrice,
                            (string) $closePrice,
                            $tradeableAt
                        );
                    }
                }
            }
        }

        // No pre-flight exchange query before the close. The REST positions
        // endpoint lags the WS trade ledger by 5-20s under load; a stale empty
        // response previously skipped the close silently and left Position
        // #577 (TONUSDT, 2026-05-06) naked on the exchange. `apiClose()` now
        // builds the close order from local DB truth and trusts Binance's
        // own -2022 response as the authoritative "nothing to reduce" signal:
        // when Binance rejects a reduceOnly order with -2022 the position
        // has already been flattened (TP/SL fill, manual close, prior cancel
        // cascade), and we map that to the same `already_closed=true` shape
        // the legacy snapshot pre-flight returned. Position #755 (TONUSDT)
        // and #803 (CAKEUSDT) — TP-fill close paths — were mismarked
        // `failed` on 2026-05-06 because the legacy handler raised
        // NonNotifiableException on this signal; treating -2022 as success
        // restores the natural TP-close exit path.
        try {
            $apiResponse = $position->apiClose();
        } catch (Throwable $e) {
            // Each exchange has its own "nothing to reduce" rejection. All
            // four signals collapse to the same `already_closed=true` shape
            // — the position has been flattened (TP/SL fill, manual close,
            // prior cancel cascade), and the local close call is a harmless
            // no-op confirmation.
            //
            //   Binance: -2022     "ReduceOnly Order is rejected"
            //   Bitget:   22002    "No position to close"  (classic flash close)
            //   Bitget:   25227    "No position available to close" (UTA)
            //   Bybit:   110043 / 110017 (variants — not yet observed in prod)
            //   Kucoin:  330005    (variant — not yet observed in prod)
            $message = $e->getMessage();
            $alreadyClosed = str_contains($message, '-2022')
                || str_contains($message, 'ReduceOnly Order is rejected')
                || str_contains($message, '(code 22002)')
                || str_contains($message, 'No position to close')
                || str_contains($message, '(code 25227)')
                || str_contains($message, 'No position available to close');

            if ($alreadyClosed) {
                return [
                    'position_id' => $position->id,
                    'symbol' => $position->parsed_trading_pair,
                    'filled_limit_count' => $position->totalLimitOrdersFilled(),
                    'pump_cooldown_triggered' => $pumpCooldownTriggered,
                    'cooldown_details' => $cooldownDetails,
                    'result' => ['already_closed' => true],
                    'message' => 'Position already closed on exchange (exchange returned "nothing to reduce")',
                ];
            }

            throw $e;
        }

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
     * Fire the `position_pump_cooldown_triggered` notification (priority 1
     * / high) via NotificationService so throttling and logging go
     * through the unified pipeline. Cache-throttled at 600s per
     * exchange_symbol.
     */
    private function notifyPumpCooldown(
        ExchangeSymbol $exchangeSymbol,
        string $changePercent,
        string $threshold,
        string $currentPrice,
        string $dailyClose,
        Carbon $tradeableAt,
    ): void {
        NotificationService::send(
            user: Kraite::admin(),
            canonical: 'position_pump_cooldown_triggered',
            referenceData: [
                'token' => $exchangeSymbol->token,
                'pair' => $exchangeSymbol->parsed_trading_pair,
                'change_percent' => $changePercent,
                'threshold' => $threshold,
                'current_price' => $currentPrice,
                'daily_close' => $dailyClose,
                'tradeable_at' => $tradeableAt->format('Y-m-d H:i:s'),
            ],
            relatable: $exchangeSymbol,
            cacheKeys: ['exchange_symbol' => $exchangeSymbol->id],
        );
    }
}
