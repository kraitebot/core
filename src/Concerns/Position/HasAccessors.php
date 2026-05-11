<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns\Position;

use Kraite\Core\Models\Indicator;
use Kraite\Core\Models\IndicatorHistory;
use Kraite\Core\Support\Math;

trait HasAccessors
{
    public function getHaveAllLimitOrdersFilledAttribute(): ?bool
    {
        return $this->allLimitOrdersFilled();
    }

    public function getParsedTradingPairExtendedAttribute(): ?string
    {
        $pair = (string) ($this->parsed_trading_pair ?? '');
        $dir = (string) ($this->direction ?? '');

        return "{$pair}/{$dir}";
    }

    /**
     * $position->alpha_percentage
     * Source: alphaPathPercent()
     * Returns numeric string or "0.0".
     */
    public function getAlphaPercentageAttribute(): ?string
    {
        $val = $this->alphaPathPercent();

        return $val === null ? '0.0' : (string) $val;
    }

    /**
     * $position->alpha_limit_percentage
     * Source: percentageToReachLimitOrder()
     * Returns numeric string with 1 decimal, or "0.0".
     */
    public function getAlphaLimitPercentageAttribute(): ?string
    {
        $val = $this->percentageToReachLimitOrder();

        return sprintf('%.1f', (float) ($val ?? 0.0));
    }

    /**
     * $position->alpha_limit (legacy 0..10 bucket)
     * Source: alphaPath()
     * Returns numeric string bucket or "0".
     */
    public function getAlphaLimitAttribute(): ?string
    {
        $val = $this->alphaPath();

        return (string) ($val ?? 0);
    }

    /**
     * $position->total_limit_orders_filled
     * Source: totalLimitOrdersFilled()
     * Returns numeric string (count).
     */
    public function getTotalLimitOrdersFilledAttribute(): string
    {
        return (string) $this->totalLimitOrdersFilled();
    }

    /**
     * $position->daily_variation_percentage
     * ((current - open) / open) * 100 from latest 1d dashboard candle.
     * Returns "0.00" when unavailable.
     */
    public function getDailyVariationPercentageAttribute(): ?string
    {
        $symbol = $this->exchangeSymbol;
        if (! $symbol || $symbol->current_price === null || $symbol->current_price === '') {
            return '0.00';
        }

        // Resolve dashboard candle indicator id
        $indicatorId = Indicator::query()
            ->where('canonical', 'candle')
            ->where('type', 'dashboard')
            ->value('id');

        if (! $indicatorId) {
            return '0.00';
        }

        // Latest 1d candle row for this symbol
        $row = IndicatorHistory::query()
            ->where('indicator_id', $indicatorId)
            ->where('exchange_symbol_id', $symbol->id)
            ->where('timeframe', '1d')
            ->orderByDesc('timestamp')
            ->first();

        // Extract open[0] if present.
        $open = null;
        if ($row && is_array($row->data) && isset($row->data['open'][0])) {
            $open = (string) $row->data['open'][0];
        }

        if ($open === null || Math::equal($open, '0')) {
            return '0.00';
        }

        $current = (string) $symbol->current_price;
        $delta = Math::sub($current, $open);
        $percent = Math::mul(
            Math::div($delta, $open, 12),
            '100'
        );

        return number_format((float) $percent, 2, '.', '');
    }

    /**
     * $position->unrealized_pnl
     * Source: unrealizedPnl() domain method.
     * Returns string numeric (e.g. "0") always.
     */
    public function getUnrealizedPnlAttribute(): ?string
    {
        return (string) ($this->unrealizedPnl() ?? '0');
    }

    /**
     * $position->current_price
     * Source: latest candle close price
     * Returns numeric string or "0".
     */
    public function getCurrentPriceAttribute(): ?string
    {
        $price = $this->exchangeSymbol?->current_price;

        return ($price === null || $price === '') ? '0' : (string) $price;
    }
}
