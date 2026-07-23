<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns\ExchangeSymbol;

use Carbon\CarbonInterface;
use Kraite\Core\Trading\ExchangeSymbolTradability;

trait HasStatuses
{
    public function applySystemBlock(string $reason): bool
    {
        if ($this->system_disabled_at !== null) {
            return false;
        }

        return $this->updateSaving([
            'system_disabled_at' => now(),
            'system_disabled_reason' => $reason,
        ]);
    }

    /**
     * True only after the exchange contract has reached its terminal date.
     * `is_marked_for_delisting` is an earlier selection gate and may also
     * represent temporary absence from an active-only catalogue.
     */
    public function isDelisted(): bool
    {
        return $this->delivery_at !== null && $this->delivery_at->lessThanOrEqualTo(now());
    }

    /**
     * Timestamp when the contract became terminal. A warning-only marker has
     * no terminal date and therefore returns null.
     */
    public function delistedAt(): ?CarbonInterface
    {
        if ($this->delivery_at !== null && $this->delivery_at->lessThanOrEqualTo(now())) {
            return $this->delivery_at;
        }

        return null;
    }

    /**
     * Check if this exchange symbol is valid for trading.
     * Mirrors the scopeTradeable logic for instance-level checks.
     */
    public function isTradeable(): bool
    {
        return (new ExchangeSymbolTradability)->allows($this);
    }
}
