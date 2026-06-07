<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns\ExchangeSymbol;

use Carbon\CarbonInterface;
use Kraite\Core\Models\Kraite;

trait HasStatuses
{
    /**
     * True when the symbol is no longer being maintained by its
     * exchange. Two signals collapse into this single read:
     *
     *  - `is_marked_for_delisting` (set by sync jobs across all four
     *    exchange clients when the symbol disappears from the
     *    exchange's authoritative pair list, e.g.
     *    `UpsertExchangeSymbolsFromExchangeJob`'s orphan sweep).
     *
     *  - `delivery_at` in the past (futures delivery contracts that
     *    have already settled — Binance USDS-M flags these via the
     *    `deliveryDate` field on the symbol metadata).
     */
    public function isDelisted(): bool
    {
        if ($this->is_marked_for_delisting) {
            return true;
        }

        if ($this->delivery_at !== null && $this->delivery_at->isPast()) {
            return true;
        }

        return false;
    }

    /**
     * Best-effort timestamp for when the symbol stopped trading. Null
     * if the symbol is still active. The two signals split as:
     *
     *  - When `delivery_at` is set and past: that's the canonical
     *    delisting moment (exchange-published).
     *  - When `is_marked_for_delisting` is true without a delivery
     *    date: we fall back to `updated_at` (the moment our orphan
     *    sweep flipped the flag — best proxy we have).
     */
    public function delistedAt(): ?CarbonInterface
    {
        if ($this->delivery_at !== null && $this->delivery_at->isPast()) {
            return $this->delivery_at;
        }

        if ($this->is_marked_for_delisting) {
            return $this->updated_at;
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
