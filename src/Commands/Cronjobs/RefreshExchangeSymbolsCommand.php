<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Cronjobs;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kraite\Core\Jobs\Atomic\ApiSystem\UpsertExchangeSymbolsFromExchangeJob;
use Kraite\Core\Jobs\Lifecycles\ApiSystem\DiscoverCMCTokensForOrphanedSymbolsJob;
use Kraite\Core\Jobs\Lifecycles\ApiSystem\SyncLeverageBracketsJob;
use Kraite\Core\Jobs\Lifecycles\ExchangeSymbol\TouchTaapiDataForExchangeSymbolsJob;
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
 *
 * Note: Binance overlap marking is handled reactively by ExchangeSymbolObserver.
 * Binance must sync first so other exchanges can check token overlap on creation.
 */
final class RefreshExchangeSymbolsCommand extends BaseCommand
{
    protected $signature = 'cronjobs:refresh-exchange-symbols
                            {--clean : Truncate all operational tables and start fresh}
                            {--exchange= : Only refresh a specific exchange (binance, bybit, kucoin, bitget)}';

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

        DB::transaction(function () use ($exchanges, $blockUuid) {
            // Index 1: Binance syncs FIRST (required for overlaps_with_binance detection)
            // The observer checks if tokens exist on Binance when creating non-Binance symbols.
            $binance = $exchanges->firstWhere('canonical', 'binance');
            $otherExchanges = $exchanges->where('canonical', '!=', 'binance');

            if ($binance !== null) {
                Step::create([
                    'class' => UpsertExchangeSymbolsFromExchangeJob::class,
                    'arguments' => ['apiSystemId' => $binance->id],
                    'block_uuid' => $blockUuid,
                    'index' => 1,
                ]);

                $this->verboseLine("  - Created upsert step for {$binance->name} (Index 1 - syncs first)");
            }

            // Index 2: Other exchanges sync AFTER Binance completes
            foreach ($otherExchanges as $exchange) {
                Step::create([
                    'class' => UpsertExchangeSymbolsFromExchangeJob::class,
                    'arguments' => ['apiSystemId' => $exchange->id],
                    'block_uuid' => $blockUuid,
                    'index' => 2,
                ]);

                $this->verboseLine("  - Created upsert step for {$exchange->name} (Index 2)");
            }

            // Index 3: Parent lifecycle jobs that run in PARALLEL after all exchanges sync:
            // - DiscoverCMCTokensForOrphanedSymbolsJob: discovers CMC tokens for orphaned symbols
            // - TouchTaapiDataForExchangeSymbolsJob: touches TAAPI to check data availability (Binance only)
            // - SyncLeverageBracketsJob: syncs leverage brackets for each exchange
            // All run at index 3 so they execute in parallel.
            // child_block_uuid is required so the parent waits for all children to complete.
            Step::create([
                'class' => DiscoverCMCTokensForOrphanedSymbolsJob::class,
                'arguments' => [],
                'block_uuid' => $blockUuid,
                'child_block_uuid' => (string) Str::uuid(),
                'index' => 3,
            ]);

            Step::create([
                'class' => TouchTaapiDataForExchangeSymbolsJob::class,
                'arguments' => [],
                'block_uuid' => $blockUuid,
                'child_block_uuid' => (string) Str::uuid(),
                'index' => 3,
            ]);

            // Sync leverage brackets for each exchange
            // Uses JobProxy to resolve exchange-specific lifecycle implementations:
            // - Binance, BitGet: batch fetch (default)
            // - Bybit, KuCoin: per-symbol fetch (override)
            foreach ($exchanges as $exchange) {
                $account = Account::admin($exchange->canonical);
                $resolver = JobProxy::with($account);
                $lifecycleClass = $resolver->resolve(SyncLeverageBracketsJob::class);

                Step::create([
                    'class' => $lifecycleClass,
                    'arguments' => ['apiSystemId' => $exchange->id],
                    'block_uuid' => $blockUuid,
                    'child_block_uuid' => (string) Str::uuid(),
                    'index' => 3,
                ]);

                $this->verboseLine("  - Created leverage brackets sync step for {$exchange->name} (Index 3)");
            }

            $this->verboseLine('  - Created CMC discovery lifecycle step (Index 3)');
            $this->verboseLine('  - Created TAAPI touch lifecycle step (Index 3, Binance only)');
        });

        $this->verboseInfo("Done. {$exchanges->count()} exchange step(s) created.");

        return self::SUCCESS;
    }
}
