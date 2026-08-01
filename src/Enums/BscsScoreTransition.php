<?php

declare(strict_types=1);

namespace Kraite\Core\Enums;

enum BscsScoreTransition: string
{
    case Activated = 'activated';
    case Maximum = 'maximum';
    case Cleared = 'cleared';

    public const MAXIMUM_SCORE = 100;

    public static function detect(?int $previousScore, int $score): ?self
    {
        if ($score === self::MAXIMUM_SCORE && $previousScore !== self::MAXIMUM_SCORE) {
            return self::Maximum;
        }

        if ($previousScore === 0 && $score > 0) {
            return self::Activated;
        }

        if ($previousScore !== null && $previousScore > 0 && $score === 0) {
            return self::Cleared;
        }

        return null;
    }
}
