<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Position;

use Illuminate\Support\Facades\DB;
use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Math;

/**
 * SyncPositionQuantityFromExchangeJob (Atomic)
 *
 * Pulls the live position size from the exchange and overwrites
 * positions.quantity with abs(size). Dispatched on every LIMIT
 * partial-fill event so the local mirror tracks the running net
 * size between the initial MARKET fill and the eventual full-fill
 * WAP refresh.
 *
 * Strict scope: this job ONLY rewrites positions.quantity. It does
 * not touch opening_price, breakeven, profit_percentage, status,
 * was_waped, or any TP/SL order. Full-fill WAP keeps owning the
 * breakeven + TP price + TP qty surface; conflating the two would
 * trigger a TP modify REST call per partial-fill chunk.
 *
 * Skip semantics — both produce a benign no-op result, never a
 * failure:
 *   - Position no longer in an active status (closing, closed,
 *     cancelling, cancelled, failed): a concurrent close workflow
 *     owns the row, do not stomp.
 *   - No matching position on the exchange snapshot: position was
 *     closed externally between dispatch and pickup; the
 *     replacement / close orchestration covers that route, this
 *     job stays inert.
 */
final class SyncPositionQuantityFromExchangeJob extends BaseApiableJob
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
        $position->refresh();

        if (! in_array($position->status, $position->activeStatuses(), true)) {
            return [
                'position_id' => $position->id,
                'skipped' => true,
                'reason' => "position status '{$position->status}' is not active — partial-fill quantity sync skipped",
            ];
        }

        $exchangePosition = $this->resolveExchangePosition();

        if ($exchangePosition === null) {
            return [
                'position_id' => $position->id,
                'matched_on_exchange' => false,
                'message' => 'no matching position on exchange — silent no-op (close/replace workflow will handle it)',
            ];
        }

        $rawQty = (string) ($exchangePosition['positionAmt']
            ?? $exchangePosition['size']
            ?? $exchangePosition['total']
            ?? $exchangePosition['qty']
            ?? '0');

        $absQty = Math::lt($rawQty, '0') ? Math::sub('0', $rawQty) : $rawQty;

        if (Math::equal($absQty, '0')) {
            return [
                'position_id' => $position->id,
                'matched_on_exchange' => true,
                'exchange_size' => $absQty,
                'message' => 'exchange size is zero — close workflow will reconcile, not overwriting quantity',
            ];
        }

        $previousQty = (string) $position->quantity;
        $newQty = $this->formatQuantity($absQty);

        // Skip the lock + write + observer cascade when the value matches.
        // Cron safety net fires every tick; without this guard each tick
        // wastes a row lock and a no-op updateSaving on every position
        // that has a steady-state PARTIALLY_FILLED LIMIT.
        if (Math::equal($previousQty, $newQty)) {
            return [
                'position_id' => $position->id,
                'matched_on_exchange' => true,
                'previous_quantity' => $previousQty,
                'new_quantity' => $newQty,
                'message' => 'positions.quantity already in sync — no change',
            ];
        }

        DB::transaction(function () use ($position, $newQty): void {
            Position::query()->whereKey($position->id)->lockForUpdate()->first();
            $position->updateSaving(['quantity' => $newQty]);
        });

        return [
            'position_id' => $position->id,
            'matched_on_exchange' => true,
            'previous_quantity' => $previousQty,
            'new_quantity' => $newQty,
            'message' => 'positions.quantity refreshed from exchange snapshot',
        ];
    }

    /**
     * Locate this position in the freshly-fetched exchange positions
     * payload. Tolerant of the per-exchange shape variants the mappers
     * already normalise (positionAmt + symbol from Binance, size +
     * symbol from Bitget, etc.). Match key is symbol + direction with
     * a fall-back to symbol-only for one-way mode where the mapper
     * encodes positionSide=BOTH.
     */
    private function resolveExchangePosition(): ?array
    {
        $response = $this->position->account->apiQueryPositions();
        $positions = $response->result ?? [];

        if (! is_array($positions) || $positions === []) {
            return null;
        }

        $tradingPair = mb_strtoupper(mb_trim((string) $this->position->parsed_trading_pair));
        $direction = mb_strtoupper((string) $this->position->direction);

        foreach ($positions as $key => $row) {
            if (! is_array($row)) {
                continue;
            }

            $rowSymbol = mb_strtoupper(mb_trim((string) ($row['symbol'] ?? $key)));
            if ($rowSymbol !== $tradingPair) {
                continue;
            }

            $rowSide = mb_strtoupper((string) ($row['positionSide'] ?? $row['holdSide'] ?? 'BOTH'));
            if ($rowSide === 'BOTH' || $rowSide === $direction) {
                return $row;
            }
        }

        return null;
    }

    private function formatQuantity(string $rawQty): string
    {
        $exchangeSymbol = $this->position->exchangeSymbol;

        if ($exchangeSymbol === null) {
            return $rawQty;
        }

        return api_format_quantity($rawQty, $exchangeSymbol);
    }
}
