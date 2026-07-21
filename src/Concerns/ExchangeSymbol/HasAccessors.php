<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns\ExchangeSymbol;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Trading\Exchange\Exchange;

trait HasAccessors
{
    /**
     * Look up the displayed trading pair by raw asset name and api_system_id.
     * Used to convert exchange-specific formats (e.g., PF_XBTUSD) to clean display (e.g., XBT/USD).
     *
     * @param  string  $asset  The raw exchange asset name (e.g., PF_XBTUSD, BTCUSDT)
     * @param  int  $apiSystemId  The API system ID to scope the lookup
     * @return string|null The displayed trading pair (e.g., XBT/USD) or null if not found
     */
    public static function getDisplayedTradingPairByAsset(string $asset, int $apiSystemId): ?string
    {
        /** @var static|null $exchangeSymbol */
        $exchangeSymbol = static::query()
            ->where('asset', $asset)
            ->where('api_system_id', $apiSystemId)
            ->first(['token', 'quote']);

        if (! $exchangeSymbol) {
            return null;
        }

        return $exchangeSymbol->displayed_trading_pair;
    }

    /**
     * Sibling exchange-symbol rows for the same underlying token, on
     * OTHER exchanges (excludes self). Linkage is via `symbol_id` —
     * the canonical FK that survived the 2025-12-08 architecture
     * simplification (which dropped `base_asset_mappers`).
     *
     * Centralised here so any cross-exchange propagation (observer
     * fan-out, bulk approval flips, audit traversals) goes through one
     * shared definition.
     *
     * @return Collection<int, ExchangeSymbol>
     */
    public function getOthersFromExchanges(): Collection
    {
        if ($this->symbol_id === null) {
            return ExchangeSymbol::query()->whereRaw('1 = 0')->get();
        }

        return ExchangeSymbol::query()
            ->where('symbol_id', $this->symbol_id)
            ->where('id', '!=', $this->id)
            ->get();
    }

    /**
     * Accessor for the `parsed_trading_pair` attribute.
     * Returns a unified trading pair string from token and quote.
     * Token and quote are now stored directly on exchange_symbols.
     */
    public function getParsedTradingPairAttribute(): ?string
    {
        $apiSystem = ApiSystem::find($this->api_system_id);
        if (! $apiSystem) {
            return null;
        }

        $dataMapper = Exchange::forCanonical($apiSystem->canonical)->mapper();

        return $dataMapper->baseWithQuote($this->token, $this->quote);
    }

    public function getParsedTradingPairExtendedAttribute(): ?string
    {
        return "{$this->parsed_trading_pair}/{$this->indicators_timeframe}/{$this->direction}";
    }

    // ->parsed_trading_pair_with_exchange
    public function getParsedTradingPairWithExchangeAttribute(): ?string
    {
        return "{$this->parsed_trading_pair}@{$this->apiSystem->name}";
    }

    /**
     * Accessor for the `displayed_trading_pair` attribute.
     * Returns a clean, human-readable trading pair: TOKEN/QUOTE (e.g., XBT/USD).
     * Unlike parsed_trading_pair, this doesn't include exchange-specific prefixes.
     */
    public function getDisplayedTradingPairAttribute(): ?string
    {
        if (! $this->token || ! $this->quote) {
            return null;
        }

        return "{$this->token}/{$this->quote}";
    }

    /**
     * Accessor for the `is_tradeable` attribute.
     * Returns whether this exchange symbol is valid for trading.
     */
    public function getIsTradeableAttribute(): bool
    {
        return $this->isTradeable();
    }

    /**
     * Accessor for the `current_price` attribute.
     * Returns the latest candle close price for the 5m timeframe.
     * Returns null if the candle is stale (older than 15 minutes).
     */
    public function getCurrentPriceAttribute(): ?string
    {
        $latestCandle = $this->candles()
            ->where('timeframe', '5m')
            ->orderByDesc('timestamp')
            ->first(['close', 'candle_time_utc']);

        if (! $latestCandle) {
            return null;
        }

        // Freshness check: candle must be within the last 15 minutes (3x 5m candles)
        $candleTime = Carbon::parse($latestCandle->candle_time_utc);
        $freshnessThreshold = Carbon::now()->subMinutes(15);

        if ($candleTime->lt($freshnessThreshold)) {
            return null;
        }

        return $latestCandle->close;
    }

    /**
     * Accessor for the `last_known_price` attribute.
     * Returns the latest candle close price for the 5m timeframe WITHOUT freshness check.
     * Used for min notional calculations where we need a price estimate, not real-time accuracy.
     */
    public function getLastKnownPriceAttribute(): ?string
    {
        $latestCandle = $this->candles()
            ->where('timeframe', '5m')
            ->orderByDesc('timestamp')
            ->first(['close']);

        return $latestCandle?->close;
    }
}
