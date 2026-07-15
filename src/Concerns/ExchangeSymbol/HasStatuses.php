<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns\ExchangeSymbol;

use Carbon\CarbonInterface;
use Kraite\Core\Models\Kraite;

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
        // Must overlap with Binance (TAAPI uses Binance as reference)
        if (! $this->overlaps_with_binance) {
            return false;
        }

        // Must have TAAPI indicator data
        if (! ($this->api_statuses['has_taapi_data'] ?? false)) {
            return false;
        }

        // Must not have indicator data issues
        if ($this->has_no_indicator_data) {
            return false;
        }

        // Must not be marked for delisting
        if ($this->is_marked_for_delisting) {
            return false;
        }

        // Automatic safety gates must never reuse the sysadmin-owned flag.
        if ($this->system_disabled_at !== null) {
            return false;
        }

        // Must share the same contract unit as the Binance reference
        if ($this->is_price_aligned !== true) {
            return false;
        }

        // Must not have price trend misalignment
        if ($this->has_price_trend_misalignment) {
            return false;
        }

        // Must not have early direction change (path inconsistency)
        if ($this->has_early_direction_change) {
            return false;
        }

        // Must not have invalid indicator direction (all timeframes exhausted)
        if ($this->has_invalid_indicator_direction) {
            return false;
        }

        // Must not be manually blocked (null or true is allowed, false blocks)
        if ($this->is_manually_enabled === false) {
            return false;
        }

        // Must have passed backtesting approval (operator-driven flag,
        // propagates across all exchanges via ExchangeSymbolObserver).
        if ($this->was_backtesting_approved !== true) {
            return false;
        }

        // Must have a concluded direction
        if ($this->direction === null) {
            return false;
        }

        // Must not be in cooldown period (tradeable_at null or in the past)
        if ($this->tradeable_at !== null && $this->tradeable_at->isFuture()) {
            return false;
        }

        // Must have a concluded timeframe
        if ($this->indicators_timeframe === null) {
            return false;
        }

        // Must have leverage brackets data
        if ($this->leverage_brackets === null) {
            return false;
        }

        // Must have correlation data for the symbol's concluded timeframe
        $correlationType = Kraite::correlationType();
        $correlationField = 'btc_correlation_'.$correlationType;
        $correlationData = $this->{$correlationField};

        if (! is_array($correlationData) || ! isset($correlationData[$this->indicators_timeframe])) {
            return false;
        }

        return true;
    }
}
