<?php

declare(strict_types=1);

namespace Kraite\Core\Enums;

enum OrderStatus: string
{
    case New = 'NEW';
    case PartiallyFilled = 'PARTIALLY_FILLED';
    case Filled = 'FILLED';
    case Triggered = 'TRIGGERED';
    case Cancelled = 'CANCELLED';
    case Expired = 'EXPIRED';
    case Rejected = 'REJECTED';

    /**
     * @return list<string>
     */
    public static function workingValues(): array
    {
        return [self::New->value, self::PartiallyFilled->value];
    }

    /**
     * @return list<string>
     */
    public static function workingOrFilledValues(): array
    {
        return [...self::workingValues(), self::Filled->value];
    }

    /**
     * @return list<string>
     */
    public static function terminalWithoutFillValues(): array
    {
        return [self::Cancelled->value, self::Expired->value, self::Rejected->value];
    }

    public function isWorkingOnExchange(): bool
    {
        return $this === self::New || $this === self::PartiallyFilled;
    }

    public function closesPosition(): bool
    {
        return $this === self::Filled || $this === self::Triggered;
    }

    public function requiresReplacement(): bool
    {
        return in_array($this, [self::Cancelled, self::Expired, self::Rejected], true);
    }
}
