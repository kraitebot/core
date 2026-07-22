<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Cronjobs;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kraite\Core\Jobs\Models\ExchangeSymbol\CalculateBtcCorrelationJob;
use Kraite\Core\Jobs\Models\ExchangeSymbol\CalculateBtcElasticityJob;
use Kraite\Core\Jobs\Models\ExchangeSymbol\DispatchPerSymbolKlineBlocksJob;
use Kraite\Core\Jobs\Models\ExchangeSymbol\FetchKlinesJob;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\TokenMapper;
use StepDispatcher\Models\Step;
use StepDispatcher\Support\BaseCommand;

final class FetchKlinesCommand extends BaseCommand
{
    protected $signature = 'kraite:cron-fetch-klines
        {--clean : Truncate candles, steps, and related operational tables}
        {--exchange_symbol_id= : Fetch klines for a specific exchange symbol}
        {--canonical= : Filter by API system canonical (e.g., binance, bybit)}
        {--only-active-positions : Fetch klines only for symbols with active positions}
        {--reference-set : Fetch klines only for the BSCS reference basket (config kraite.market_regime.symbols). Requires --canonical.}
        {--timeframe= : Candle timeframe (if not provided, uses timeframes from ApiSystem)}
        {--limit=5 : Number of candles to fetch}
        {--output : Output verbose information}';

    protected $description = 'Create FetchKlinesJob steps for exchange symbols';

    public function handle(): int
    {
        if ($this->option('clean')) {
            $this->verboseWarn('Cleaning operational tables...');

            DB::statement('SET FOREIGN_KEY_CHECKS=0;');

            DB::table('steps')->truncate();
            DB::table('steps_dispatcher_ticks')->truncate();
            DB::table('candles')->truncate();
            DB::table('api_request_logs')->truncate();
            DB::table('notification_logs')->truncate();

            // Clear correlation/elasticity data from exchange_symbols
            ExchangeSymbol::query()->update([
                'btc_correlation_pearson' => null,
                'btc_correlation_spearman' => null,
                'btc_correlation_rolling' => null,
                'btc_elasticity_long' => null,
                'btc_elasticity_short' => null,
            ]);

            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            cleanLogsFolder();

            $this->verboseInfo('✓ Operational tables truncated, correlation/elasticity data cleared, and logs cleared.');
        }

        $exchangeSymbolId = $this->option('exchange_symbol_id');
        $canonical = $this->option('canonical');
        $onlyActivePositions = $this->option('only-active-positions');
        $referenceSet = (bool) $this->option('reference-set');
        $limit = (int) $this->option('limit');

        // Check if --timeframe option was explicitly provided
        $explicitTimeframe = $this->resolveExplicitTimeframe();

        if ($exchangeSymbolId) {
            return $this->handleSingleSymbol((int) $exchangeSymbolId, $explicitTimeframe, $limit);
        }

        if ($onlyActivePositions) {
            return $this->handleActivePositionsOnly($explicitTimeframe, $limit);
        }

        if ($referenceSet) {
            $canonicalString = is_string($canonical) ? $canonical : null;
            if ($canonicalString === null || $canonicalString === '') {
                $this->verboseError('--reference-set requires --canonical (the BSCS basket has the same tokens on multiple exchanges; pick one).');

                return self::FAILURE;
            }

            return $this->handleReferenceSet($canonicalString, $explicitTimeframe, $limit);
        }

        /** @var string|null $canonicalString */
        $canonicalString = is_string($canonical) ? $canonical : null;

        return $this->handleBulkSymbols($canonicalString, $explicitTimeframe, $limit);
    }

    /** @return array<int, string>|null */
    private function resolveExplicitTimeframe(): ?array
    {
        $tf = $this->option('timeframe');

        return is_string($tf) && $tf !== '' ? [$tf] : null;
    }

    /**
     * @param  array<int, string>|null  $explicitTimeframe
     * @return array<int, string>|null
     */
    private function getTimeframesForApiSystem(ApiSystem $apiSystem, ?array $explicitTimeframe): ?array
    {
        if ($explicitTimeframe !== null) {
            return $explicitTimeframe;
        }

        $timeframes = Kraite::timeframes();
        if (empty($timeframes)) {
            $this->verboseError("No timeframes configured on the kraite singleton row — cannot fetch klines for '{$apiSystem->canonical}'.");

            return null;
        }

        return $timeframes;
    }

    /** @param  array<int, string>|null  $explicitTimeframe */
    private function handleSingleSymbol(int $exchangeSymbolId, ?array $explicitTimeframe, int $limit): int
    {
        $exchangeSymbol = ExchangeSymbol::with('apiSystem')->find($exchangeSymbolId);
        if (! $exchangeSymbol) {
            $this->verboseError("Exchange symbol {$exchangeSymbolId} not found");

            return self::FAILURE;
        }

        if (! $exchangeSymbol->apiSystem->is_active) {
            $this->verboseWarn("Exchange '{$exchangeSymbol->apiSystem->canonical}' is inactive — nothing to do.");

            return self::SUCCESS;
        }

        $timeframes = $this->getTimeframesForApiSystem($exchangeSymbol->apiSystem, $explicitTimeframe);
        if ($timeframes === null) {
            return self::FAILURE;
        }
        $this->verboseInfo("Timeframes for {$exchangeSymbol->apiSystem->canonical}: ".implode(', ', $timeframes));

        // Find BTC symbol with the same quote as the exchange symbol
        $btcSymbol = $this->getBtcSymbolForQuote($exchangeSymbol->apiSystem, $exchangeSymbol->quote);
        if ($btcSymbol === null) {
            $this->verboseError("BTC/{$exchangeSymbol->quote} symbol not found for {$exchangeSymbol->apiSystem->canonical}.");

            return self::FAILURE;
        }

        $blockUuid = Str::uuid()->toString();
        $this->createKlineStepsForTimeframes($blockUuid, $btcSymbol->id, $timeframes, $limit, 1);

        if ($exchangeSymbol->id === $btcSymbol->id) {
            $this->verboseInfo('Created '.count($timeframes)." steps for {$exchangeSymbol->parsed_trading_pair} (BTC baseline)");

            return self::SUCCESS;
        }

        $this->createKlineStepsForTimeframes($blockUuid, $exchangeSymbol->id, $timeframes, $limit, 2);
        $this->createCorrelationElasticitySteps($blockUuid, $exchangeSymbol->id);

        $totalKlineSteps = count($timeframes) * 2;
        $this->verboseInfo("Created {$totalKlineSteps} kline steps + 2 correlation/elasticity steps for {$exchangeSymbol->parsed_trading_pair}");

        return self::SUCCESS;
    }

    /** @param  array<int, string>|null  $explicitTimeframe */
    private function handleBulkSymbols(?string $canonical, ?array $explicitTimeframe, int $limit): int
    {
        $apiSystemsQuery = ApiSystem::query()->activeExchange()->whereHas('exchangeSymbols');
        if ($canonical) {
            $apiSystemsQuery->where('canonical', $canonical);
        }
        $apiSystems = $apiSystemsQuery->get();
        if ($apiSystems->isEmpty()) {
            $this->verboseWarn('No API systems with exchange symbols found');

            return self::SUCCESS;
        }

        $totalBtcSteps = $totalSymbolSteps = $totalCorrelationSteps = 0;

        foreach ($apiSystems as $apiSystem) {
            $this->verboseInfo("Processing exchange: {$apiSystem->canonical}");
            $timeframes = $this->getTimeframesForApiSystem($apiSystem, $explicitTimeframe);
            if ($timeframes === null) {
                $this->verboseWarn("  Skipping {$apiSystem->canonical}: no timeframes configured");

                continue;
            }
            $this->verboseInfo('  Timeframes: '.implode(', ', $timeframes));

            // Get all BTC symbols for this exchange (one per quote)
            $btcSymbols = $this->getBtcSymbolsForApiSystem($apiSystem, operationalOnly: true);
            if ($btcSymbols->isEmpty()) {
                $this->verboseWarn("  Skipping {$apiSystem->canonical}: no BTC symbols found");

                continue;
            }

            $btcSymbolIds = $btcSymbols->map(static fn (ExchangeSymbol $symbol): int => $symbol->id)->values()->all();
            $symbols = ExchangeSymbol::query()
                ->where('api_system_id', $apiSystem->id)
                ->whereNotIn('id', $btcSymbolIds)
                ->needsOperationalMonitoring()
                ->get();
            $exchangeSymbolIds = $symbols->map(static fn (ExchangeSymbol $symbol): int => $symbol->id)->values()->all();

            // BTC baselines share one block at index 1. The per-symbol
            // orchestrator at index 2 fires once BTC klines land in the DB,
            // then spawns an independent block per symbol so each symbol's
            // correlation runs the moment its own klines complete.
            $this->createBtcAndPerSymbolBlock(
                btcSymbolIds: $btcSymbolIds,
                exchangeSymbolIds: $exchangeSymbolIds,
                timeframes: $timeframes,
                limit: $limit
            );

            $btcStepsCreated = count($timeframes) * $btcSymbols->count();
            $totalBtcSteps += $btcStepsCreated;
            $totalSymbolSteps += count($timeframes) * $symbols->count();
            $totalCorrelationSteps += $symbols->count() * 2;

            $this->verboseInfo("  Created {$btcStepsCreated} BTC steps (".$btcSymbols->pluck('quote')->implode(', ').') and dispatched per-symbol blocks for '.$symbols->count().' symbols');
        }

        $this->verboseInfo("Total: {$totalBtcSteps} BTC + {$totalSymbolSteps} symbol + {$totalCorrelationSteps} correlation/elasticity steps");

        return self::SUCCESS;
    }

    /**
     * Fetch klines for the BSCS reference basket only — the small fixed
     * symbol set used by the cascade detection cron and the hourly BSCS
     * compute. Lets the 15-minute kline schedule cover BTC + 4 alts on
     * Binance without touching the other ~600 symbols every quarter
     * hour.
     *
     * Symbol list comes from `config('kraite.market_regime.symbols')` —
     * defaults to BTCUSDT/ETHUSDT/SOLUSDT/BNBUSDT/XRPUSDT, matching the
     * tokens validated through the 6-event Python backtest. Missing
     * symbols (token absent on the chosen canonical) are silently
     * skipped — the cron stays useful even if an exchange retires a
     * listing.
     *
     * Unlike the bulk path, this does NOT chain correlation/elasticity
     * jobs. The reference set drives BSCS / cascade detection only;
     * those signals don't need per-pair correlation outputs against
     * BTC (the calculator does its own in-memory correlation).
     *
     * @param  array<int, string>|null  $explicitTimeframe
     */
    private function handleReferenceSet(string $canonical, ?array $explicitTimeframe, int $limit): int
    {
        $apiSystem = ApiSystem::query()
            ->where('canonical', $canonical)
            ->activeExchange()
            ->first();

        if ($apiSystem === null) {
            $this->verboseWarn("API system '{$canonical}' not found — nothing to do.");

            return self::SUCCESS;
        }

        $timeframes = $this->getTimeframesForApiSystem($apiSystem, $explicitTimeframe);
        if ($timeframes === null) {
            return self::FAILURE;
        }

        $exchangeSymbolIds = $this->resolveReferenceSetSymbolIds($apiSystem);
        if (empty($exchangeSymbolIds)) {
            $this->verboseWarn("No reference symbols found on '{$canonical}' — skipping.");

            return self::SUCCESS;
        }

        $blockUuid = Str::uuid()->toString();
        foreach ($exchangeSymbolIds as $exchangeSymbolId) {
            $this->createKlineStepsForTimeframes($blockUuid, $exchangeSymbolId, $timeframes, $limit, 1);
        }

        $stepsCreated = count($timeframes) * count($exchangeSymbolIds);
        $this->verboseInfo("Reference set ({$canonical}): {$stepsCreated} kline steps queued for ".count($exchangeSymbolIds).' symbols × '.implode(',', $timeframes).'.');

        return self::SUCCESS;
    }

    /**
     * Resolve the configured BSCS basket (token+quote pairs like 'BTCUSDT')
     * to existing exchange_symbol IDs on the chosen exchange. Pairs are
     * split on a 3-or-4-letter quote suffix (USDT / USDC / USD / BUSD /
     * EUR / GBP); callers that want a different parse should set the
     * symbols entry to its native pair format already split via TokenMapper.
     *
     * @return array<int, int>
     */
    private function resolveReferenceSetSymbolIds(ApiSystem $apiSystem): array
    {
        /** @var array<int, string> $configured */
        $configured = (array) config('kraite.market_regime.symbols', []);

        $ids = [];
        foreach ($configured as $pair) {
            [$token, $quote] = $this->splitPair((string) $pair);
            if ($token === null || $quote === null) {
                continue;
            }

            $row = ExchangeSymbol::query()
                ->where('api_system_id', $apiSystem->id)
                ->where('token', $token)
                ->where('quote', $quote)
                ->needsOperationalMonitoring()
                ->first();

            if ($row !== null) {
                $ids[] = (int) $row->id;
            }
        }

        return $ids;
    }

    /**
     * Split a trading-pair string ('BTCUSDT') into [token, quote].
     * Returns [null, null] if no recognised quote suffix matches.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function splitPair(string $pair): array
    {
        $pair = mb_strtoupper(trim($pair));
        $quotes = ['USDT', 'USDC', 'BUSD', 'USD', 'EUR', 'GBP'];

        foreach ($quotes as $quote) {
            if (str_ends_with($pair, $quote)) {
                $token = mb_substr($pair, 0, -mb_strlen($quote));

                return [$token === '' ? null : $token, $quote];
            }
        }

        return [null, null];
    }

    /** @param  array<int, string>|null  $explicitTimeframe */
    private function handleActivePositionsOnly(?array $explicitTimeframe, int $limit): int
    {
        $binanceApiSystem = ApiSystem::active()->where('canonical', 'binance')->first();
        if ($binanceApiSystem === null) {
            $this->verboseError('Binance API system not found in database.');

            return self::FAILURE;
        }

        $timeframes = $this->getTimeframesForApiSystem($binanceApiSystem, $explicitTimeframe);
        if ($timeframes === null) {
            return self::FAILURE;
        }
        $this->verboseInfo('Timeframes (Binance): '.implode(', ', $timeframes));

        // Get all BTC symbols for Binance (one per quote)
        $btcSymbols = $this->getBtcSymbolsForApiSystem($binanceApiSystem);
        if ($btcSymbols->isEmpty()) {
            $this->verboseError('No Binance BTC symbols found in database.');

            return self::FAILURE;
        }

        /** @var Collection<int, ExchangeSymbol> $exchangeSymbols */
        $exchangeSymbols = Position::active()->whereNotNull('exchange_symbol_id')
            ->with('exchangeSymbol.apiSystem')->get()->pluck('exchangeSymbol')->filter()->unique('id');
        if ($exchangeSymbols->isEmpty()) {
            $this->verboseInfo('No active positions found.');

            return self::SUCCESS;
        }
        $this->verboseInfo("Found {$exchangeSymbols->count()} exchange symbols with active positions.");

        $binanceSymbolIds = collect();
        foreach ($exchangeSymbols as $exchangeSymbol) {
            $binanceSymbolId = $this->resolveToBinanceSymbol($exchangeSymbol, $binanceApiSystem->id);
            if ($binanceSymbolId === null) {
                $this->verboseLine("  - Skipped {$exchangeSymbol->token}/{$exchangeSymbol->quote} ({$exchangeSymbol->apiSystem->canonical}): no Binance equivalent found");

                continue;
            }
            $binanceSymbolIds->push($binanceSymbolId);
        }
        $binanceSymbolIds = $binanceSymbolIds->unique();
        if ($binanceSymbolIds->isEmpty()) {
            $this->verboseInfo('No Binance equivalents found for active positions.');

            return self::SUCCESS;
        }
        $this->verboseInfo("Mapped to {$binanceSymbolIds->count()} unique Binance exchange symbols.");

        $btcSymbolIds = $btcSymbols->map(static fn (ExchangeSymbol $symbol): int => $symbol->id)->values()->all();
        $binanceSymbols = ExchangeSymbol::whereIn('id', $binanceSymbolIds)->whereNotIn('id', $btcSymbolIds)->get();
        $binanceSymbolIdsList = $binanceSymbols->map(static fn (ExchangeSymbol $symbol): int => $symbol->id)->values()->all();

        // BTC klines run first (shared block, index 1). The orchestrator at
        // index 2 then spawns one block per active-position symbol so each
        // symbol's correlation fires independently of the others.
        $this->createBtcAndPerSymbolBlock(
            btcSymbolIds: $btcSymbolIds,
            exchangeSymbolIds: $binanceSymbolIdsList,
            timeframes: $timeframes,
            limit: $limit,
            protectedExchangeSymbolIds: $binanceSymbolIdsList,
        );

        $btcStepsCreated = count($timeframes) * $btcSymbols->count();
        $stepsCreated = count($timeframes) * $binanceSymbols->count();
        $correlationSteps = $binanceSymbols->count() * 2;

        $this->verboseInfo("Created {$btcStepsCreated} BTC baseline steps (".$btcSymbols->pluck('quote')->implode(', ').')');
        foreach ($binanceSymbols as $binanceSymbol) {
            $this->verboseLine("  - Per-symbol block queued for {$binanceSymbol->parsed_trading_pair}");
        }
        $this->verboseInfo("Queued {$btcStepsCreated} BTC + {$stepsCreated} FetchKlinesJob steps + {$correlationSteps} correlation/elasticity steps across {$binanceSymbols->count()} per-symbol blocks.");

        return self::SUCCESS;
    }

    private function resolveToBinanceSymbol(ExchangeSymbol $exchangeSymbol, int $binanceApiSystemId): ?int
    {
        if ($exchangeSymbol->api_system_id === $binanceApiSystemId) {
            $this->verboseLine("  - {$exchangeSymbol->token}/{$exchangeSymbol->quote} is on Binance (ID: {$exchangeSymbol->id})");

            return $exchangeSymbol->id;
        }

        $token = $exchangeSymbol->token;
        $quote = $exchangeSymbol->quote;

        // Try direct token match on Binance
        $binanceSymbol = ExchangeSymbol::query()
            ->where('api_system_id', $binanceApiSystemId)->where('token', $token)->where('quote', $quote)->first();
        if ($binanceSymbol !== null) {
            $this->verboseLine("  - {$token}/{$quote} ({$exchangeSymbol->apiSystem->canonical}) -> Binance ID: {$binanceSymbol->id} (direct match)");

            return $binanceSymbol->id;
        }

        // Try TokenMapper lookup
        $tokenMapper = TokenMapper::query()
            ->where('other_token', $token)->where('other_api_system_id', $exchangeSymbol->api_system_id)->first();
        if ($tokenMapper === null) {
            return null;
        }

        $binanceSymbol = ExchangeSymbol::query()
            ->where('api_system_id', $binanceApiSystemId)->where('token', $tokenMapper->binance_token)->where('quote', $quote)->first();
        if ($binanceSymbol !== null) {
            $this->verboseLine("  - {$token}/{$quote} ({$exchangeSymbol->apiSystem->canonical}) -> {$tokenMapper->binance_token}/{$quote} Binance ID: {$binanceSymbol->id} (via TokenMapper)");

            return $binanceSymbol->id;
        }

        return null;
    }

    /**
     * Get BTC symbol for a specific quote on an exchange.
     * Uses TokenMapper to handle exchange-specific token names (e.g., XBT on KuCoin).
     */
    private function getBtcSymbolForQuote(ApiSystem $apiSystem, string $quote): ?ExchangeSymbol
    {
        $btcToken = $this->resolveBtcTokenForApiSystem($apiSystem);

        return ExchangeSymbol::query()
            ->where('api_system_id', $apiSystem->id)
            ->where('token', $btcToken)
            ->where('quote', $quote)
            ->first();
    }

    /**
     * Get all BTC symbols for an exchange (one per quote).
     * Uses TokenMapper to handle exchange-specific token names (e.g., XBT on KuCoin).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, ExchangeSymbol>
     */
    private function getBtcSymbolsForApiSystem(
        ApiSystem $apiSystem,
        bool $operationalOnly = false,
    ): \Illuminate\Database\Eloquent\Collection {
        $btcToken = $this->resolveBtcTokenForApiSystem($apiSystem);

        return ExchangeSymbol::query()
            ->where('api_system_id', $apiSystem->id)
            ->where('token', $btcToken)
            ->when($operationalOnly, static fn ($query) => $query->needsOperationalMonitoring())
            ->get();
    }

    /**
     * Resolve the BTC token name for an exchange using TokenMapper.
     * Returns the exchange-specific token (e.g., 'XBT' for KuCoin) or 'BTC' as default.
     */
    private function resolveBtcTokenForApiSystem(ApiSystem $apiSystem): string
    {
        /** @var string $btcToken */
        $btcToken = config('kraite.correlation.btc_token', 'BTC');

        // Check if this exchange uses a different token name for BTC
        $tokenMapper = TokenMapper::query()
            ->where('binance_token', $btcToken)
            ->where('other_api_system_id', $apiSystem->id)
            ->first();

        return $tokenMapper->other_token ?? $btcToken;
    }

    /**
     * Create the shared BTC block that gates every per-symbol block.
     *
     * Block layout:
     *   index 1: FetchKlinesJob for each BTC symbol × timeframe (parallel)
     *   index 2: DispatchPerSymbolKlineBlocksJob — runs once BTC klines land,
     *            spawns one independent block per target symbol with its own
     *            klines at index 1 and correlation/elasticity at index 2.
     *
     * @param  array<int, int>  $btcSymbolIds
     * @param  array<int, int>  $exchangeSymbolIds  non-BTC symbols to fan out
     * @param  array<int, string>  $timeframes
     * @param  array<int, int>  $protectedExchangeSymbolIds
     */
    private function createBtcAndPerSymbolBlock(
        array $btcSymbolIds,
        array $exchangeSymbolIds,
        array $timeframes,
        int $limit,
        array $protectedExchangeSymbolIds = [],
    ): void {
        if (empty($btcSymbolIds) || empty($exchangeSymbolIds)) {
            return;
        }

        $blockUuid = Str::uuid()->toString();

        foreach ($btcSymbolIds as $btcSymbolId) {
            $this->createKlineStepsForTimeframes($blockUuid, $btcSymbolId, $timeframes, $limit, 1);
        }

        Step::create([
            'block_uuid' => $blockUuid,
            'index' => 2,
            'class' => DispatchPerSymbolKlineBlocksJob::class,
            'queue' => 'indicators',
            'arguments' => [
                'exchangeSymbolIds' => $exchangeSymbolIds,
                'timeframes' => $timeframes,
                'limit' => $limit,
                'protectedExchangeSymbolIds' => $protectedExchangeSymbolIds,
            ],
        ]);
    }

    /** @param  array<int, string>  $timeframes */
    private function createKlineStepsForTimeframes(string $blockUuid, int $exchangeSymbolId, array $timeframes, int $limit, int $index): void
    {
        foreach ($timeframes as $timeframe) {
            Step::create([
                'block_uuid' => $blockUuid,
                'index' => $index,
                'class' => FetchKlinesJob::class,
                'queue' => 'indicators',
                'arguments' => ['exchangeSymbolId' => $exchangeSymbolId, 'timeframe' => $timeframe, 'limit' => $limit],
            ]);
        }
    }

    private function createCorrelationElasticitySteps(string $blockUuid, int $exchangeSymbolId): void
    {
        Step::create([
            'block_uuid' => $blockUuid,
            'index' => 3,
            'class' => CalculateBtcCorrelationJob::class,
            'queue' => 'indicators',
            'arguments' => ['exchangeSymbolId' => $exchangeSymbolId],
        ]);
        Step::create([
            'block_uuid' => $blockUuid,
            'index' => 3,
            'class' => CalculateBtcElasticityJob::class,
            'queue' => 'indicators',
            'arguments' => ['exchangeSymbolId' => $exchangeSymbolId],
        ]);
    }
}
