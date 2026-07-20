<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Cronjobs;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kraite\Core\Jobs\Atomic\ApiSystem\UpsertExchangeSymbolsFromExchangeJob;
use Kraite\Core\Jobs\Lifecycles\ApiSystem\DiscoverCMCTokensForOrphanedSymbolsJob;
use Kraite\Core\Jobs\Lifecycles\ApiSystem\SyncLeverageBracketsJob;
use Kraite\Core\Jobs\Lifecycles\ExchangeSymbol\TouchTaapiDataForExchangeSymbolsJob;
use Kraite\Core\Jobs\Lifecycles\ExchangeSymbol\VerifyPriceAlignmentsJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Support\Proxies\JobProxy;
use StepDispatcher\Models\Step;
use StepDispatcher\Support\BaseCommand;

/**
 * Discovers and upserts exchange symbols directly from exchange APIs.
 *
 * Workflow:
 * - Index 1: Binance syncs FIRST (required for overlaps_with_binance detection)
 * - Index 2: Other exchanges sync (can detect Binance overlap via observer)
 * - Index 3: Parallel tasks after all exchanges sync:
 *   - Discover CMC tokens for orphaned symbols
 *   - Verify TAAPI data (Binance only)
 *   - Sync leverage brackets per exchange
 * - Index 4: Verify price alignment
 *
 * Targeted refreshes compact these phases into contiguous indexes 1, 2, and 3.
 *
 * Note: Binance overlap marking is handled reactively by ExchangeSymbolObserver.
 * Binance must sync first so other exchanges can check token overlap on creation.
 */
final class RefreshExchangeSymbolsCommand extends BaseCommand
{
    protected $signature = 'kraite:cron-refresh-exchange-symbols
                            {--clean : Truncate all operational tables and start fresh}
                            {--exchange= : Only refresh a specific exchange (binance, bybit, kucoin, bitget)}
                            {--with-brackets : Refresh leverage brackets for every active symbol}';

    protected $description = 'Refresh exchange symbols from exchange APIs and discover CMC tokens';

    public function handle(): int
    {
        if ($this->option('clean')) {
            $this->verboseWarn('Cleaning operational tables...');

            DB::statement('SET FOREIGN_KEY_CHECKS=0;');

            // Truncate all operational tables for a complete fresh start.
            // NOTE: symbols table is NEVER cleaned as it contains core reference data.
            // exchange_symbols IS cleaned - it will be repopulated from exchange APIs.
            DB::table('steps')->truncate();
            DB::table('steps_dispatcher_ticks')->truncate();
            DB::table('exchange_symbols')->truncate();
            DB::table('candles')->truncate();
            DB::table('api_request_logs')->truncate();
            DB::table('forbidden_hostnames')->truncate();
            DB::table('notification_logs')->truncate();

            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            cleanLogsFolder();

            $this->verboseInfo('✓ All operational tables truncated and logs cleared.');
        }

        $exchangeFilter = $this->option('exchange');
        $withBrackets = (bool) $this->option('with-brackets');

        $exchanges = ApiSystem::where('is_exchange', true)
            ->when($exchangeFilter, static function ($query) use ($exchangeFilter) {
                return $query->where('canonical', $exchangeFilter);
            })
            ->get();

        if ($exchanges->isEmpty()) {
            $this->verboseError('No exchanges found.');

            return self::FAILURE;
        }

        // Generate shared block_uuid for all steps in this workflow
        $blockUuid = (string) Str::uuid();

        $this->verboseInfo('Dispatching exchange symbol refresh steps...');

        DB::transaction(function () use ($exchanges, $blockUuid, $withBrackets) {
            $nextIndex = 1;

            // Index 1: Binance syncs FIRST (required for overlaps_with_binance detection)
            // The observer checks if tokens exist on Binance when creating non-Binance symbols.
            $binance = $exchanges->firstWhere('canonical', 'binance');
            $otherExchanges = $exchanges->where('canonical', '!=', 'binance');

            if ($binance !== null) {
                Step::create([
                    'class' => UpsertExchangeSymbolsFromExchangeJob::class,
                    'queue' => 'cronjobs',
                    'arguments' => ['apiSystemId' => $binance->id],
                    'block_uuid' => $blockUuid,
                    'index' => $nextIndex,
                ]);

                $this->verboseLine("  - Created upsert step for {$binance->name} (Index {$nextIndex} - syncs first)");

                $nextIndex++;
            }

            if ($otherExchanges->isNotEmpty()) {
                // Other exchanges sync after Binance when it is in scope, or first for a targeted refresh.
                foreach ($otherExchanges as $exchange) {
                    Step::create([
                        'class' => UpsertExchangeSymbolsFromExchangeJob::class,
                        'queue' => 'cronjobs',
                        'arguments' => ['apiSystemId' => $exchange->id],
                        'block_uuid' => $blockUuid,
                        'index' => $nextIndex,
                    ]);

                    $this->verboseLine("  - Created upsert step for {$exchange->name} (Index {$nextIndex})");
                }

                $nextIndex++;
            }

            $postSyncIndex = $nextIndex;

            // Parent lifecycle jobs run in parallel after all exchanges sync:
            // - DiscoverCMCTokensForOrphanedSymbolsJob: discovers CMC tokens for orphaned symbols
            // - TouchTaapiDataForExchangeSymbolsJob: touches TAAPI to check data availability (Binance only)
            // - SyncLeverageBracketsJob: syncs leverage brackets for each exchange
            //   only on the explicit six-hour/manual --with-brackets run
            // All share one index so they execute in parallel.
            //
            // Each orchestrator self-elects to parent mode inside its own compute()
            // via buildChildChainOnce() — only when it actually has work
            // to spawn. Pre-setting child_block_uuid here would zombie the step
            // when the orchestrator's empty-work early-return fires.
            Step::create([
                'class' => DiscoverCMCTokensForOrphanedSymbolsJob::class,
                'queue' => 'cronjobs',
                'arguments' => [],
                'block_uuid' => $blockUuid,
                'index' => $postSyncIndex,
            ]);

            Step::create([
                'class' => TouchTaapiDataForExchangeSymbolsJob::class,
                'queue' => 'cronjobs',
                'arguments' => [],
                'block_uuid' => $blockUuid,
                'index' => $postSyncIndex,
            ]);

            // Sync leverage brackets for each exchange
            // Uses JobProxy to resolve exchange-specific lifecycle implementations:
            // - Binance: batch fetch (default)
            // - Bitget, Bybit, KuCoin: per-symbol fetch (override)
            if ($withBrackets) {
                foreach ($exchanges as $exchange) {
                    $account = Account::admin($exchange->canonical);
                    $resolver = JobProxy::with($account);
                    $lifecycleClass = $resolver->resolve(SyncLeverageBracketsJob::class);

                    Step::create([
                        'class' => $lifecycleClass,
                        'queue' => 'cronjobs',
                        'arguments' => ['apiSystemId' => $exchange->id],
                        'block_uuid' => $blockUuid,
                        'index' => $postSyncIndex,
                    ]);

                    $this->verboseLine("  - Created leverage brackets sync step for {$exchange->name} (Index {$postSyncIndex})");
                }
            }

            $this->verboseLine("  - Created CMC discovery lifecycle step (Index {$postSyncIndex})");
            $this->verboseLine("  - Created TAAPI touch lifecycle step (Index {$postSyncIndex}, Binance only)");

            // After CMC discovery has resolved symbol_id,
            // verify each naming-divergent cross-exchange symbol's live price
            // against its Binance same-asset sibling. A unit-divergent contract
            // (KuCoin FLOKI vs Binance 1000FLOKI) carries a replicated price
            // wrong by the contract ratio — it is flagged is_price_aligned=false
            // and switched off so it never trades.
            Step::create([
                'class' => VerifyPriceAlignmentsJob::class,
                'queue' => 'cronjobs',
                'arguments' => [],
                'block_uuid' => $blockUuid,
                'index' => $postSyncIndex + 1,
            ]);

            $this->verboseLine('  - Created price-alignment verification lifecycle step (Index '.($postSyncIndex + 1).')');
        });

        $this->verboseInfo("Done. {$exchanges->count()} exchange step(s) created.");

        return self::SUCCESS;
    }
}
