<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\ExchangeSymbol;

use Illuminate\Support\Facades\DB;
use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Support\NotificationService;
use Kraite\Core\Support\Proxies\ApiDataMapperProxy;

/**
 * VerifyPriceAlignmentJob
 *
 * Confirms a non-Binance exchange symbol's live price approximately matches its
 * Binance same-asset (symbol_id) sibling, and gates trading on the result.
 *
 * Two exchanges can list the SAME asset under different contract units — Binance
 * `1000FLOKI` = 1000 × KuCoin `FLOKI`. The price stream replicates Binance's
 * mark_price onto same-asset rows with no contract-ratio scaling, so a
 * unit-divergent contract ends up with a price wrong by that ratio (KuCoin FLOKI
 * carried Binance 1000FLOKI's price — ~1000× too high). We don't trade those.
 *
 * If the live price matches Binance within `kraite.price_alignment.tolerance`,
 * the symbol is flagged `is_price_aligned = true`. Otherwise it is flagged
 * `is_price_aligned = false`, switched off (`is_manually_enabled = false` — so it
 * drops out of trading even if re-enabled), and the owner is notified once.
 */
final class VerifyPriceAlignmentJob extends BaseApiableJob
{
    public ExchangeSymbol $exchangeSymbol;

    public function __construct(int $exchangeSymbolId)
    {
        $this->exchangeSymbol = ExchangeSymbol::with('apiSystem')->findOrFail($exchangeSymbolId);
    }

    public function relatable()
    {
        return $this->exchangeSymbol;
    }

    public function assignExceptionHandler(): void
    {
        $canonical = $this->exchangeSymbol->apiSystem->canonical;
        $this->exceptionHandler = BaseExceptionHandler::make($canonical)
            ->withAccount(Account::admin($canonical));
    }

    public function computeApiable()
    {
        $canonical = $this->exchangeSymbol->apiSystem->canonical;

        // The Binance same-asset sibling is the price reference. Its mark_price
        // is the one the stream replicates everywhere; we measure THIS symbol's
        // own exchange price against it.
        $binance = ExchangeSymbol::query()
            ->whereHas('apiSystem', function ($query): void {
                $query->where('canonical', 'binance');
            })
            ->notDelisted()
            ->where('symbol_id', $this->exchangeSymbol->symbol_id)
            ->where('quote', $this->exchangeSymbol->quote)
            ->first();

        $binancePrice = $binance?->mark_price;

        if ($binance === null || ! is_numeric($binancePrice) || (float) $binancePrice <= 0.0) {
            // No reference to compare against — leave the flag untouched.
            return [
                'exchange_symbol_id' => $this->exchangeSymbol->id,
                'skipped' => 'no Binance reference price',
            ];
        }

        // This symbol's own live price, straight from its exchange (public
        // endpoint via the admin account, same path the order jobs use).
        $mapper = new ApiDataMapperProxy($canonical);
        $properties = $mapper->prepareQueryMarkPriceProperties($this->exchangeSymbol);
        $response = Account::admin($canonical)->withApi()->getMarkPrice($properties);
        $livePrice = $mapper->resolveQueryMarkPriceResponse($response);

        if (! is_numeric($livePrice) || (float) $livePrice <= 0.0) {
            return [
                'exchange_symbol_id' => $this->exchangeSymbol->id,
                'skipped' => 'no live price from exchange',
            ];
        }

        $ratio = (float) $livePrice / (float) $binancePrice;
        $tolerance = (float) config('kraite.price_alignment.tolerance', 0.10);

        if ($ratio >= (1.0 - $tolerance) && $ratio <= (1.0 + $tolerance)) {
            return $this->markAligned($ratio);
        }

        return $this->disableForPriceMismatch((float) $livePrice, (float) $binancePrice, $ratio);
    }

    /**
     * @return array<string, mixed>
     */
    private function markAligned(float $ratio): array
    {
        if ($this->exchangeSymbol->is_price_aligned !== true) {
            $this->exchangeSymbol->updateSaving(['is_price_aligned' => true]);
        }

        return [
            'exchange_symbol_id' => $this->exchangeSymbol->id,
            'symbol' => $this->exchangeSymbol->parsed_trading_pair,
            'aligned' => true,
            'ratio' => round($ratio, 4),
        ];
    }

    /**
     * Flag the divergent symbol, switch it off, and notify once.
     *
     * @return array<string, mixed>
     */
    private function disableForPriceMismatch(float $livePrice, float $binancePrice, float $ratio): array
    {
        // Only notify on the transition into the divergent state — a re-check
        // every refresh must not re-page. `cacheKeys` throttles per symbol on
        // top of this, but the transition guard makes the single-shot intent
        // explicit and survives a throttle-window reset.
        $wasAligned = $this->exchangeSymbol->is_price_aligned !== false;

        DB::transaction(function (): void {
            $symbol = ExchangeSymbol::query()
                ->whereKey($this->exchangeSymbol->id)
                ->lockForUpdate()
                ->first();

            if ($symbol !== null) {
                $symbol->updateSaving([
                    'is_price_aligned' => false,
                    'is_manually_enabled' => false,
                ]);
            }
        });

        if ($wasAligned) {
            NotificationService::send(
                user: Kraite::admin(),
                canonical: 'exchange_symbol_price_misaligned',
                referenceData: [
                    'symbol' => $this->exchangeSymbol->parsed_trading_pair,
                    'exchange' => $this->exchangeSymbol->apiSystem->canonical,
                    'exchange_symbol_id' => $this->exchangeSymbol->id,
                    'live_price' => $livePrice,
                    'binance_price' => $binancePrice,
                    'ratio' => round($ratio, 6),
                ],
                cacheKeys: ['exchange_symbol_id' => (string) $this->exchangeSymbol->id],
            );
        }

        return [
            'exchange_symbol_id' => $this->exchangeSymbol->id,
            'symbol' => $this->exchangeSymbol->parsed_trading_pair,
            'aligned' => false,
            'ratio' => round($ratio, 6),
            'disabled' => true,
        ];
    }
}
