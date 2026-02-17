<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns\Order;

trait HasGetters
{
    // Is this the last limit order? (the one with biggest quantity).
    public function isLastLimitOrder(): bool
    {
        $last = $this->position
            ->orders()
            ->where('type', 'LIMIT')
            ->where('position_side', $this->position->direction)
            ->orderByDesc('reference_quantity')
            ->orderByDesc('id')
            ->first();

        if (! $last) {
            return false;
        }

        // Compare exchange ids strictly and null‑safe.
        return (string) $this->exchange_order_id === (string) $last->exchange_order_id;
    }
}
