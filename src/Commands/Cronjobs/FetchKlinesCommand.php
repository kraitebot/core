<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Cronjobs;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kraite\Core\Jobs\Models\ExchangeSymbol\CalculateBtcCorrelationJob;
use Kraite\Core\Jobs\Models\ExchangeSymbol\CalculateBtcElasticityJob;
use Kraite\Core\Jobs\Models\ExchangeSymbol\FetchKlinesJob;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
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
        $limit = (int) $this->option('limit');

        // Check if --timeframe option was explicitly provided
        $explicitTimeframe = $this->resolveExplicitTimeframe();

        if ($exchangeSymbolId) {
            return $this->handleSingleSymbol((int) $exchangeSymbolId, $explicitTimeframe, $limit);
        }

        if ($onlyActivePositions) {
            return $this->handleActivePositionsOnly($explicitTimeframe, $limit);
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
        $timeframes = $apiSystem->timeframes;
        if (! is_array($timeframes) || empty($timeframes)) {
            $this->verboseError("ApiSystem '{$apiSystem->canonical}' has no timeframes configured.");

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
        $apiSystemsQuery = ApiSystem::query()->where('is_exchange', true)->whereNotNull('timeframes')->whereHas('exchangeSymbols');
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
            $btcSymbols = $this->getBtcSymbolsForApiSystem($apiSystem);
            if ($btcSymbols->isEmpty()) {
                $this->verboseWarn("  Skipping {$apiSystem->canonical}: no BTC symbols found");

                continue;
            }

            $btcSymbolIds = $btcSymbols->pluck('id')->all();
            $symbols = ExchangeSymbol::query()->where('api_system_id', $apiSystem->id)->whereNotIn('id', $btcSymbolIds)->get();
            $blockUuid = Str::uuid()->toString();

            // Create kline steps for ALL BTC baselines (one per quote)
            foreach ($btcSymbols as $btcSymbol) {
                $this->createKlineStepsForTimeframes($blockUuid, $btcSymbol->id, $timeframes, $limit, 1);
            }
            $btcStepsCreated = count($timeframes) * $btcSymbols->count();
            $totalBtcSteps += $btcStepsCreated;
            $this->verboseInfo("  Created {$btcStepsCreated} BTC steps (".$btcSymbols->pluck('quote')->implode(', ').')');

            $exchangeSymbolSteps = 0;
            foreach ($symbols as $symbol) {
                $this->createKlineStepsForTimeframes($blockUuid, $symbol->id, $timeframes, $limit, 2);
                $exchangeSymbolSteps += count($timeframes);
            }
            $totalSymbolSteps += $exchangeSymbolSteps;
            $this->verboseInfo("  Created {$exchangeSymbolSteps} symbol steps for {$symbols->count()} symbols");

            foreach ($symbols as $symbol) {
                $this->createCorrelationElasticitySteps($blockUuid, $symbol->id);
            }
            $totalCorrelationSteps += $symbols->count() * 2;
        }

        $this->verboseInfo("Total: {$totalBtcSteps} BTC + {$totalSymbolSteps} symbol + {$totalCorrelationSteps} correlation/elasticity steps");

        return self::SUCCESS;
    }

    /** @param  array<int, string>|null  $explicitTimeframe */
    private function handleActivePositionsOnly(?array $explicitTimeframe, int $limit): int
    {
        $binanceApiSystem = ApiSystem::where('canonical', 'binance')->first();
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

        /** @var \Illuminate\Support\Collection<int, ExchangeSymbol> $exchangeSymbols */
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

        $btcSymbolIds = $btcSymbols->pluck('id')->all();
        $binanceSymbols = ExchangeSymbol::whereIn('id', $binanceSymbolIds)->whereNotIn('id', $btcSymbolIds)->get();
        $blockUuid = Str::uuid()->toString();

        // Create kline steps for ALL BTC baselines (one per quote)
        foreach ($btcSymbols as $btcSymbol) {
            $this->createKlineStepsForTimeframes($blockUuid, $btcSymbol->id, $timeframes, $limit, 1);
        }
        $btcStepsCreated = count($timeframes) * $btcSymbols->count();
        $this->verboseInfo("Created {$btcStepsCreated} BTC baseline steps (".$btcSymbols->pluck('quote')->implode(', ').')');

        $stepsCreated = 0;
        foreach ($binanceSymbols as $binanceSymbol) {
            $this->createKlineStepsForTimeframes($blockUuid, $binanceSymbol->id, $timeframes, $limit, 2);
            $stepsCreated += count($timeframes);
            $this->verboseLine('  - Created '.count($timeframes)." steps for {$binanceSymbol->parsed_trading_pair}");
        }

        foreach ($binanceSymbols as $binanceSymbol) {
            $this->createCorrelationElasticitySteps($blockUuid, $binanceSymbol->id);
        }

        $correlationSteps = $binanceSymbols->count() * 2;
        $this->verboseInfo("Created {$btcStepsCreated} BTC + {$stepsCreated} FetchKlinesJob steps + {$correlationSteps} correlation/elasticity steps for active positions.");

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
    private function getBtcSymbolsForApiSystem(ApiSystem $apiSystem): \Illuminate\Database\Eloquent\Collection
    {
        $btcToken = $this->resolveBtcTokenForApiSystem($apiSystem);

        return ExchangeSymbol::query()
            ->where('api_system_id', $apiSystem->id)
            ->where('token', $btcToken)
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

    /** @param  array<int, string>  $timeframes */
    private function createKlineStepsForTimeframes(string $blockUuid, int $exchangeSymbolId, array $timeframes, int $limit, int $index): void
    {
        foreach ($timeframes as $timeframe) {
            Step::create([
                'block_uuid' => $blockUuid,
                'index' => $index,
                'class' => FetchKlinesJob::class,
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
            'arguments' => ['exchangeSymbolId' => $exchangeSymbolId],
        ]);
        Step::create([
            'block_uuid' => $blockUuid,
            'index' => 3,
            'class' => CalculateBtcElasticityJob::class,
            'arguments' => ['exchangeSymbolId' => $exchangeSymbolId],
        ]);
    }
}
