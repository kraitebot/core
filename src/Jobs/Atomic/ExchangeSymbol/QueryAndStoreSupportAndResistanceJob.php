<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\ExchangeSymbol;

use Illuminate\Support\Carbon;
use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Models\ExchangeSymbol;

/**
 * QueryAndStoreSupportAndResistanceJob (Atomic)
 *
 * Runs as part of the direction-finalization chain, AFTER a direction is
 * successfully concluded for an exchange symbol. Copies the seven pivot
 * levels that PivotPointsIndicator already landed in
 * `exchange_symbols.indicators_values['pivotpoints']['result']` into
 * dedicated DECIMAL columns so the selection phase can use them inline.
 *
 * Why dedicated columns instead of reading JSON: the S/R proximity check
 * runs at selection time and compares against live mark_price. Having the
 * levels as proper columns keeps the SQL scope (and any future live
 * filtering) trivial and keeps the hot path free of JSON extraction.
 *
 * Idempotent: writing the same data twice is a no-op as long as the
 * underlying indicator payload is stable.
 */
final class QueryAndStoreSupportAndResistanceJob extends BaseQueueableJob
{
    public int $exchangeSymbolId;

    public function __construct(int $exchangeSymbolId)
    {
        $this->exchangeSymbolId = $exchangeSymbolId;
    }

    public function relatable()
    {
        return ExchangeSymbol::find($this->exchangeSymbolId);
    }

    public function compute()
    {
        $exchangeSymbol = ExchangeSymbol::findOrFail($this->exchangeSymbolId);

        // Guard: if direction got cleared between the conclude step and
        // this step firing, bail. Writing pivots onto a directionless
        // symbol serves no purpose — the S/R gate only consults them
        // when a direction is present.
        if ($exchangeSymbol->direction === null) {
            return [
                'exchange_symbol_id' => $exchangeSymbol->id,
                'status' => 'skipped',
                'reason' => 'direction_cleared',
            ];
        }

        $pivots = $exchangeSymbol->indicators_values['pivotpoints']['result'] ?? null;

        if (! is_array($pivots)) {
            return [
                'exchange_symbol_id' => $exchangeSymbol->id,
                'status' => 'skipped',
                'reason' => 'pivotpoints_not_present_in_indicators_values',
            ];
        }

        $payload = [
            'pivot_r3' => $this->asDecimal($pivots['r3'] ?? null),
            'pivot_r2' => $this->asDecimal($pivots['r2'] ?? null),
            'pivot_r1' => $this->asDecimal($pivots['r1'] ?? null),
            'pivot_p' => $this->asDecimal($pivots['p'] ?? null),
            'pivot_s1' => $this->asDecimal($pivots['s1'] ?? null),
            'pivot_s2' => $this->asDecimal($pivots['s2'] ?? null),
            'pivot_s3' => $this->asDecimal($pivots['s3'] ?? null),
            'pivot_synced_at' => Carbon::now(),
        ];

        $exchangeSymbol->updateSaving($payload);

        return [
            'exchange_symbol_id' => $exchangeSymbol->id,
            'status' => 'stored',
            'levels' => array_intersect_key($payload, array_flip(['pivot_r1', 'pivot_p', 'pivot_s1'])),
        ];
    }

    /**
     * Normalise a pivot level into a DECIMAL-compatible string. TAAPI
     * returns floats that can carry arbitrary precision; we keep them
     * as strings so DB storage doesn't lose digits to float->decimal
     * casting.
     */
    private function asDecimal(mixed $value): ?string
    {
        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        return (string) $value;
    }
}
