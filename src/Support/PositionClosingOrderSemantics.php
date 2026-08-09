<?php

declare(strict_types=1);

namespace Kraite\Core\Support;

final class PositionClosingOrderSemantics
{
    public function matches(
        bool $hedgeMode,
        string $direction,
        ?string $side,
        ?string $positionSide,
        ?bool $reduceOnly,
        ?bool $closePosition,
    ): bool {
        $direction = mb_strtoupper(mb_trim($direction));
        $side = mb_strtoupper(mb_trim((string) $side));
        $positionSide = mb_strtoupper(mb_trim((string) $positionSide));

        if (! in_array($direction, ['LONG', 'SHORT'], strict: true)) {
            return false;
        }

        $closingSide = $direction === 'LONG' ? 'SELL' : 'BUY';

        if ($side !== $closingSide) {
            return false;
        }

        if ($hedgeMode) {
            return $positionSide === $direction;
        }

        if (! in_array($positionSide, ['', 'BOTH'], strict: true)) {
            return false;
        }

        return $reduceOnly === true || $closePosition === true;
    }
}
