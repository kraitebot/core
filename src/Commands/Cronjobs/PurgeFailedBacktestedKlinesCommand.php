<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Cronjobs;

use Illuminate\Support\Facades\DB;
use Kraite\Core\Models\ExchangeSymbol;
use StepDispatcher\Support\BaseCommand;

/**
 * PurgeFailedBacktestedKlinesCommand
 *
 * Deletes every candle row for ExchangeSymbols whose backtest review was
 * rejected. The token won't be re-tested or traded, so the candles are
 * dead weight in the table — purging keeps the candle store lean.
 *
 * Reference symbols are exempt regardless of review status. "Rejected for
 * trading" does NOT mean "candles are worthless": the BTC reference token
 * is the alignment series every correlation/elasticity computation runs
 * against, and the market-regime basket feeds the BSCS calculator. Purging
 * those starves the selection engine fleet-wide — rejecting BTC in admin
 * (2026-07-10) left BTC with 5 candles, degraded elasticity to unusable
 * stubs, and silently blocked every LONG position opening.
 *
 * Idempotent: re-runs are no-ops once the rejected symbols are clean.
 * Approving a previously-rejected symbol later will just re-fetch candles
 * via kraite:cron-fetch-klines.
 */
final class PurgeFailedBacktestedKlinesCommand extends BaseCommand
{
    protected $signature = 'kraite:cron-purge-failed-backtested-klines
                            {--dry-run : Show what would be deleted without deleting}
                            {--output : Display command output (silent by default)}';

    protected $description = 'Delete all candles for ExchangeSymbols with backtesting_review_status = rejected, except market-reference symbols.';

    public function handle(): int
    {
        $btcReferenceToken = (string) config('kraite.correlation.btc_token', 'BTC');
        $regimeBasketAssets = (array) config('kraite.market_regime.symbols', []);

        $rejectedIds = ExchangeSymbol::query()
            ->where('backtesting_review_status', 'rejected')
            ->where('token', '!=', $btcReferenceToken)
            ->where(static function ($query) use ($regimeBasketAssets): void {
                // NULL asset must not slip into the exemption — `NOT IN`
                // is three-valued on NULL and would silently skip the row.
                $query->whereNull('asset')->orWhereNotIn('asset', $regimeBasketAssets);
            })
            ->pluck('id');

        if ($rejectedIds->isEmpty()) {
            $this->verboseInfo('No rejected exchange symbols — nothing to purge.');

            return self::SUCCESS;
        }

        $candleCount = DB::table('candles')->whereIn('exchange_symbol_id', $rejectedIds)->count();

        if ($candleCount === 0) {
            $this->verboseInfo(sprintf('%d rejected symbol(s); candles table already clean.', $rejectedIds->count()));

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->verboseWarn(sprintf(
                'DRY RUN: would delete %d candle(s) for %d rejected symbol(s).',
                $candleCount,
                $rejectedIds->count()
            ));

            return self::SUCCESS;
        }

        $deleted = DB::table('candles')
            ->whereIn('exchange_symbol_id', $rejectedIds)
            ->delete();

        $this->verboseInfo(sprintf(
            'Deleted %d candle(s) across %d rejected symbol(s).',
            $deleted,
            $rejectedIds->count()
        ));

        return self::SUCCESS;
    }
}
