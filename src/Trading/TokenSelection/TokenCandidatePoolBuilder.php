<?php

declare(strict_types=1);

namespace Kraite\Core\Trading\TokenSelection;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSnapshot;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Kraite as KraiteSettings;
use Kraite\Core\Models\TokenMapper;
use Kraite\Core\Trading\Kraite;
use LogicException;

/**
 * Builds the account's eligible token pool before ranking begins.
 */
final class TokenCandidatePoolBuilder
{
    /** @return Collection<int, ExchangeSymbol> */
    public function build(Account $account): Collection
    {
        $candidates = $account->availableExchangeSymbols()
            ->where('api_system_id', $account->api_system_id);

        $binanceApiSystemId = ApiSystem::canonical('binance')->value('id');
        if ($account->api_system_id !== $binanceApiSystemId) {
            $binanceTradeableTokens = ExchangeSymbol::query()
                ->tradeable()
                ->where('api_system_id', $binanceApiSystemId)
                ->pluck('token');

            $candidates = $candidates->whereIn('token', $binanceTradeableTokens);
        }

        $openPositions = ApiSnapshot::getFrom($account, 'account-positions') ?? [];
        $openPositionPairs = collect(array_keys($openPositions))
            ->map(static fn (string $key): string => mb_strtoupper(explode(':', $key)[0]));

        $openOrderPairs = collect(ApiSnapshot::getFrom($account, 'account-open-orders') ?? [])
            ->pluck('symbol')
            ->map(static fn (mixed $symbol): string => is_string($symbol) ? mb_strtoupper($symbol) : '')
            ->filter(static fn (string $symbol): bool => mb_strlen($symbol) > 0);

        $openTradingPairs = $openPositionPairs
            ->merge($openOrderPairs)
            ->unique()
            ->values();

        if ($openTradingPairs->isNotEmpty()) {
            $candidates = $candidates->reject(
                static fn (ExchangeSymbol $symbol): bool => $openTradingPairs->contains(
                    mb_strtoupper($symbol->getParsedTradingPairAttribute() ?? ''),
                ),
            );
        }

        $user = $account->user;
        if ($user === null) {
            throw new LogicException('Token candidate selection requires an account owner.');
        }

        if ($user->have_distinct_position_tokens_on_all_accounts) {
            $activeTokens = $user->positions()
                ->opened()
                ->whereNotNull('exchange_symbol_id')
                ->with('exchangeSymbol')
                ->get()
                ->pluck('exchangeSymbol.token')
                ->map(static fn (mixed $token): string => is_string($token) ? $token : '')
                ->filter(static fn (string $token): bool => mb_strlen($token) > 0)
                ->unique();

            if ($activeTokens->isNotEmpty()) {
                $excludedTokens = $this->expandTokensWithMappings($activeTokens);
                $candidates = $candidates->whereNotIn('token', $excludedTokens->all());
            }

            $cacheKey = "user:{$user->id}:reserved_tokens";
            $cachedReservedTokens = Cache::get($cacheKey, []);

            if (is_array($cachedReservedTokens) && $cachedReservedTokens !== []) {
                $expandedCachedTokens = $this->expandTokensWithMappings(collect($cachedReservedTokens));
                $candidates = $candidates->whereNotIn('token', $expandedCachedTokens->all());
            }
        }

        $correlationField = 'btc_correlation_'.KraiteSettings::correlationType();

        $candidates = $candidates->filter(static function (ExchangeSymbol $symbol) use ($correlationField): bool {
            return Kraite::hasMinOrderRequirements($symbol)
                && filled($symbol->tick_size)
                && filled($symbol->price_precision)
                && filled($symbol->quantity_precision)
                && filled($symbol->btc_elasticity_long)
                && filled($symbol->btc_elasticity_short)
                && filled($symbol->{$correlationField});
        });

        $markPriceMaxAgeSeconds = config()->integer('kraite.token_discovery.mark_price_max_age_seconds', 30);
        if ($markPriceMaxAgeSeconds <= 0) {
            return $candidates;
        }

        $freshnessThreshold = now()->subSeconds($markPriceMaxAgeSeconds);

        return $candidates->filter(static function (ExchangeSymbol $symbol) use ($freshnessThreshold): bool {
            $syncedAt = $symbol->mark_price_synced_at;

            return $syncedAt === null || $syncedAt->greaterThanOrEqualTo($freshnessThreshold);
        });
    }

    /**
     * @template TKey of array-key
     * @template TValue
     *
     * @param  Collection<TKey, TValue>  $tokens
     * @return Collection<int, covariant string>
     */
    public function expandTokensWithMappings(Collection $tokens): Collection
    {
        $normalizedTokens = $tokens
            ->map(static fn (mixed $token): string => is_string($token) ? $token : '')
            ->filter(static fn (string $token): bool => mb_strlen($token) > 0)
            ->values();

        $fromBinance = TokenMapper::query()
            ->whereIn('binance_token', $normalizedTokens)
            ->pluck('other_token')
            ->map(static fn (mixed $token): string => is_string($token) ? $token : '')
            ->filter(static fn (string $token): bool => mb_strlen($token) > 0);

        $fromOther = TokenMapper::query()
            ->whereIn('other_token', $normalizedTokens)
            ->pluck('binance_token')
            ->map(static fn (mixed $token): string => is_string($token) ? $token : '')
            ->filter(static fn (string $token): bool => mb_strlen($token) > 0);

        return $normalizedTokens
            ->merge($fromBinance)
            ->merge($fromOther)
            ->unique()
            ->values()
            ->map(static fn (string $token): string => $token);
    }
}
