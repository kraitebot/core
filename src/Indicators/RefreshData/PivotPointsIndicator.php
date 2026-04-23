<?php

declare(strict_types=1);

namespace Kraite\Core\Indicators\RefreshData;

use Kraite\Core\Abstracts\BaseIndicator;
use Kraite\Core\Contracts\Indicators\ValidationIndicator;

/**
 * PivotPointsIndicator
 *
 * Fetches TAAPI's `pivotpoints` endpoint — classic pivot levels
 * (r3/r2/r1/p/s1/s2/s3) computed on the previous candle's HLC.
 * Values are pushed into indicator_histories + mirrored on
 * exchange_symbols.indicators_values like every other conclude-indicators
 * indicator, then the QueryAndStoreSupportAndResistanceJob finalization
 * step copies the seven levels into dedicated DECIMAL columns for
 * selection-phase SQL filtering.
 *
 * Implemented as a ValidationIndicator that ALWAYS returns true so it
 * plugs into the standard pipeline without ever invalidating a
 * timeframe. Pivot levels describe where price is relative to recent
 * range — they don't themselves decide LONG / SHORT / valid / invalid.
 * The actual S/R gate is applied at token-selection time by
 * SupportResistanceProximity against live mark_price.
 */
final class PivotPointsIndicator extends BaseIndicator implements ValidationIndicator
{
    public string $endpoint = 'pivotpoints';

    public function conclusion(): bool
    {
        return $this->isValid();
    }

    public function isValid(): bool
    {
        // Unconditional pass — see class docblock. Presence / absence
        // of the pivot data itself is handled downstream by
        // QueryAndStoreSupportAndResistanceJob; we don't want a missing
        // pivot payload to drag a symbol's direction conclusion down.
        return true;
    }
}
