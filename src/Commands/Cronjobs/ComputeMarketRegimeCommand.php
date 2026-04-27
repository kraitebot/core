<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Cronjobs;

use Illuminate\Support\Str;
use Kraite\Core\Jobs\Models\MarketRegime\ComputeMarketRegimeJob;
use StepDispatcher\Models\Step;
use StepDispatcher\Support\BaseCommand;

/**
 * Hourly Black Swan Composite Score (BSCS) recompute. Phase 1 telemetry
 * only — populates `market_regime_snapshots` and the denormalised
 * columns on the `kraite` singleton; does NOT touch trading flow.
 *
 * Cadence: `:50` past the hour, out of the way of `:30` direction
 * conclusion and `:45` volatile-token disable. One run per hour is
 * sufficient — sub-signals are 1h-bar derived, sub-hour resolution
 * adds noise not signal.
 *
 * @see ~/docs/kraite/black-swan-logic.md
 */
final class ComputeMarketRegimeCommand extends BaseCommand
{
    protected $signature = 'kraite:cron-compute-market-regime';

    protected $description = 'Compute the Black Swan Composite Score (BSCS) and persist a snapshot.';

    public function handle(): int
    {
        Step::create([
            'class' => ComputeMarketRegimeJob::class,
            'queue' => 'cronjobs',
            'arguments' => [],
            'block_uuid' => (string) Str::uuid(),
            'index' => 1,
        ]);

        $this->verboseInfo('Dispatched ComputeMarketRegimeJob (BSCS recompute).');

        return self::SUCCESS;
    }
}
