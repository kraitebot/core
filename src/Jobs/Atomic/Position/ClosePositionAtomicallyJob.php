<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Position;

use Carbon\CarbonInterface;
use Illuminate\Support\Sleep;
use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Enums\PositionPresence;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\IndicatorHistory;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Math;
use Kraite\Core\Support\NotificationService;
use Kraite\Core\Support\PositionSnapshot;
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
    private const FLAT_CONFIRMATION_CONFIRMED = 'confirmed';

    private const FLAT_CONFIRMATION_NOT_FLAT = 'not_flat';

    private const FLAT_CONFIRMATION_PENDING = 'pending';

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

    public function computeApiable(): array
    {
        $position = $this->position;
        $exchangeSymbol = $position->exchangeSymbol;
        $pumpCooldownTriggered = false;
        $cooldownDetails = [];
        $tradeableAt = null;

        // Pump cooldown check
        $spikeThreshold = $exchangeSymbol->disable_on_price_spike_percentage;
        $cooldownHours = $exchangeSymbol->price_spike_cooldown_hours;

        if ($spikeThreshold !== null && Math::gt((string) $spikeThreshold, '0')) {
            $currentPrice = $exchangeSymbol->mark_price ?? $exchangeSymbol->current_price;

            // Get 1D candle close price from IndicatorHistory
            $dailyIndicator = IndicatorHistory::where('exchange_symbol_id', $exchangeSymbol->id)
                ->where('timeframe', '1d')
                ->whereHas('indicator', fn ($query) => $query->where('canonical', 'candle'))
                ->orderByDesc('timestamp')
                ->orderByDesc('id')
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
                    $diff = Math::sub((string) $currentPrice, (string) $closePrice);
                    $absDiff = Math::lt($diff, '0') ? Math::mul($diff, '-1') : $diff;
                    $changePercent = Math::mul(Math::div($absDiff, (string) $closePrice), '100');

                    if (Math::gte($changePercent, (string) $spikeThreshold)) {
                        $tradeableAt = now()->addHours($cooldownHours ?? 4);
                        $pumpCooldownTriggered = true;
                        $cooldownDetails = [
                            'current_price' => $currentPrice,
                            'daily_close' => $closePrice,
                            'change_percent' => $changePercent,
                            'threshold' => $spikeThreshold,
                            'tradeable_at' => $tradeableAt->toDateTimeString(),
                        ];
                    }
                }
            }
        }

        // A rescheduled flat-confirmation pass checks its pending
        // confirmation FIRST. Re-running the close before the check fired
        // another doomed reduceOnly MARKET at an already-flat book on every
        // 20-second re-pass — the self-inflicted rejections that tripped
        // the exchange_error_storm monitor twice on 2026-07-27/28. When
        // the snapshot turns out to have been a lag artefact (position
        // live again), the marker is cleared and the normal close attempt
        // below still runs — real exposure is never left unclosed.
        if (isset($this->step) && $this->hasPendingFlatConfirmation()) {
            if ($this->confirmBinancePositionFlat($position) === self::FLAT_CONFIRMATION_CONFIRMED) {
                $this->applyPumpCooldown($exchangeSymbol, $cooldownDetails, $tradeableAt);

                return [
                    'position_id' => $position->id,
                    'symbol' => $position->parsed_trading_pair,
                    'filled_limit_count' => $position->totalLimitOrdersFilled(),
                    'pump_cooldown_triggered' => $pumpCooldownTriggered,
                    'cooldown_details' => $cooldownDetails,
                    'result' => ['already_closed' => true],
                    'message' => 'Position confirmed flat after Binance rejected the close order',
                ];
            }
        }

        // Always attempt the close first. A pre-flight positions response can
        // lag exchange trade truth and previously skipped a required close.
        // Reconcile only after an exchange rejection.
        try {
            $apiResponse = $position->apiClose();

            if (isset($this->step) && $this->hasPendingFlatConfirmation()) {
                $this->step->update(['response' => null]);
            }
        } catch (Throwable $e) {
            $message = $e->getMessage();
            $binanceReduceOnlyRejected = $position->account->apiSystem->canonical === 'binance'
                && (str_contains($message, '-2022')
                    || str_contains($message, 'ReduceOnly Order is rejected'));
            $exchangeExplicitlyReportsNoPosition = str_contains($message, '(code 22002)')
                || str_contains($message, 'No position to close')
                || str_contains($message, '(code 25227)')
                || str_contains($message, 'No position available to close');
            $flatConfirmation = $binanceReduceOnlyRejected
                ? $this->confirmBinancePositionFlat($position)
                : self::FLAT_CONFIRMATION_NOT_FLAT;
            $confirmedFlat = $exchangeExplicitlyReportsNoPosition
                || $flatConfirmation === self::FLAT_CONFIRMATION_CONFIRMED;

            if ($flatConfirmation === self::FLAT_CONFIRMATION_PENDING) {
                return [
                    'position_id' => $position->id,
                    'symbol' => $position->parsed_trading_pair,
                    'confirmation_pending' => true,
                    'message' => 'Binance flat confirmation rescheduled',
                ];
            }

            if ($confirmedFlat) {
                $this->applyPumpCooldown($exchangeSymbol, $cooldownDetails, $tradeableAt);

                return [
                    'position_id' => $position->id,
                    'symbol' => $position->parsed_trading_pair,
                    'filled_limit_count' => $position->totalLimitOrdersFilled(),
                    'pump_cooldown_triggered' => $pumpCooldownTriggered,
                    'cooldown_details' => $cooldownDetails,
                    'result' => ['already_closed' => true],
                    'message' => $binanceReduceOnlyRejected
                        ? 'Position confirmed flat after Binance rejected the close order'
                        : 'Position already closed on exchange (exchange reported no position to close)',
                ];
            }

            throw $e;
        }

        $this->applyPumpCooldown($exchangeSymbol, $cooldownDetails, $tradeableAt);

        // Count filled limit orders for the close result.
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

    private function confirmBinancePositionFlat(Position $position): string
    {
        $snapshot = PositionSnapshot::fromApiResponse(
            $position->account,
            $position->account->apiQueryPositions(),
        );

        if (! $snapshot->isValid()
            || $snapshot->presenceOf($position) !== PositionPresence::Flat) {
            if (isset($this->step) && $this->hasPendingFlatConfirmation()) {
                $this->step->update(['response' => null]);
            }

            return self::FLAT_CONFIRMATION_NOT_FLAT;
        }

        if (isset($this->step)) {
            if ($this->hasPendingFlatConfirmation()) {
                $this->step->update(['response' => null]);

                return self::FLAT_CONFIRMATION_CONFIRMED;
            }

            $this->step->update([
                'response' => ['binance_flat_confirmation_started_at' => now()->toIso8601String()],
            ]);
            $this->rescheduleWithoutRetry(now()->addSeconds(
                (int) config('kraite.position_safety.flat_confirmation_delay_seconds', 20),
            ));

            return self::FLAT_CONFIRMATION_PENDING;
        }

        // Direct calls outside Step Dispatcher retain the synchronous
        // confirmation used by recovery tools and focused tests.
        Sleep::for(
            (int) config('kraite.position_safety.flat_confirmation_delay_seconds', 20),
        )->seconds();

        $confirmationSnapshot = PositionSnapshot::fromApiResponse(
            $position->account,
            $position->account->apiQueryPositions(),
        );

        return $confirmationSnapshot->isValid()
            && $confirmationSnapshot->presenceOf($position) === PositionPresence::Flat
                ? self::FLAT_CONFIRMATION_CONFIRMED
                : self::FLAT_CONFIRMATION_NOT_FLAT;
    }

    private function hasPendingFlatConfirmation(): bool
    {
        return is_array($this->step->response)
            && isset($this->step->response['binance_flat_confirmation_started_at']);
    }

    /**
     * @param  array<string, mixed>  $cooldownDetails
     */
    private function applyPumpCooldown(
        ExchangeSymbol $exchangeSymbol,
        array $cooldownDetails,
        ?CarbonInterface $tradeableAt,
    ): void {
        if ($tradeableAt === null || $cooldownDetails === []) {
            return;
        }

        $exchangeSymbol->updateSaving(['tradeable_at' => $tradeableAt]);

        $this->notifyPumpCooldown(
            $exchangeSymbol,
            (string) $cooldownDetails['change_percent'],
            (string) $cooldownDetails['threshold'],
            (string) $cooldownDetails['current_price'],
            (string) $cooldownDetails['daily_close'],
            $tradeableAt,
        );
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
        CarbonInterface $tradeableAt,
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
