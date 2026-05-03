<?php

declare(strict_types=1);

namespace Kraite\Core\Support;

/**
 * SupportResistanceProximity
 *
 * Computes the selection-phase proximity multiplier used in
 * HasTokenDiscovery to deprioritise tokens approaching the wrong-side
 * level for their concluded direction.
 *
 * Math:
 *   position_in_range = (mark - s1) / (r1 - s1)
 *     0.0 = sitting at S1, 1.0 = sitting at R1, outside = breakout zone
 *
 *   LONG penalty band — position ∈ (1 - safe_zone, 1.0]:
 *     multiplier = max(0, (1 - position) / safe_zone)
 *
 *   SHORT penalty band — position ∈ [0.0, safe_zone):
 *     multiplier = max(0, position / safe_zone)
 *
 *   Breakout handling (price past R3 for LONG, past S3 for SHORT):
 *     direction-matched continuation → 1.0 (full score)
 *     breakout against direction → 0.0 (hard zero)
 *
 *   Graceful degrade — any missing / non-numeric / zero-range input
 *   returns 1.0 so a symbol without pivot data yet is not
 *   unintentionally penalised. The gate is additive information;
 *   absence of information is absence of opinion.
 *
 * Kept as a pure static so selection code can call it inline without
 * instantiating anything and so it's trivially unit-testable.
 */
final class SupportResistanceProximity
{
    public static function computeMultiplier(
        string $direction,
        mixed $markPrice,
        mixed $r1,
        mixed $r3,
        mixed $s1,
        mixed $s3,
        float $safeZone = 0.20,
    ): float {
        if (! self::allNumeric($markPrice, $r1, $r3, $s1, $s3)) {
            return 1.0;
        }

        $mark = (string) $markPrice;
        $r1s = (string) $r1;
        $r3s = (string) $r3;
        $s1s = (string) $s1;
        $s3s = (string) $s3;
        $safe = (string) $safeZone;
        $direction = mb_strtoupper($direction);

        // Direction-aware breakout: price past the wide band either
        // counts as continuation (full score) or wrong-way (hard zero).
        if (Math::gt($mark, $r3s)) {
            return $direction === 'LONG' ? 1.0 : 0.0;
        }

        if (Math::lt($mark, $s3s)) {
            return $direction === 'SHORT' ? 1.0 : 0.0;
        }

        $range = Math::sub($r1s, $s1s);
        if (Math::lte($range, '0')) {
            return 1.0;
        }

        $position = Math::div(Math::sub($mark, $s1s), $range, 12);

        if ($direction === 'LONG') {
            $safeUntil = Math::sub('1', $safe);
            if (Math::lte($position, $safeUntil)) {
                return 1.0;
            }

            // Penalty band between (1-safeZone) and 1.0 — linear fade.
            $delta = Math::sub('1', $position);
            $ratio = Math::div($delta, $safe, 12);
            $clamped = Math::lt($ratio, '0') ? '0' : $ratio;

            return round((float) $clamped, 6);
        }

        if ($direction === 'SHORT') {
            if (Math::gte($position, $safe)) {
                return 1.0;
            }

            $ratio = Math::div($position, $safe, 12);
            $clamped = Math::lt($ratio, '0') ? '0' : $ratio;

            return round((float) $clamped, 6);
        }

        return 1.0;
    }

    private static function allNumeric(mixed ...$values): bool
    {
        foreach ($values as $value) {
            if ($value === null || ! is_numeric($value)) {
                return false;
            }
        }

        return true;
    }
}
