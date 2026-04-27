<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Cronjobs;

use Illuminate\Support\Str;
use Kraite\Core\Jobs\Models\MarketRegime\AnalyseBscsJob;
use StepDispatcher\Models\Step;
use StepDispatcher\Support\BaseCommand;

/**
 * Hourly BSCS gate-state machine. Reads the latest score from the
 * `kraite` singleton and arms / re-arms / releases the system cooldown
 * that blocks new opens. Distinct from `kraite:cron-compute-market-regime`
 * which writes the score in the first place — analyse acts on it.
 *
 * Cadence: `:55` past the hour, 5 minutes after the compute cron at
 * `:50`. Compute lands a fresh snapshot, analyse decides whether the
 * gate should flip.
 *
 * @see AnalyseBscsJob
 * @see ~/docs/kraite/black-swan-logic.md
 */
final class AnalyseBscsCommand extends BaseCommand
{
    protected $signature = 'kraite:cron-analyse-bscs';

    protected $description = 'Analyse the latest BSCS score and arm/release the system cooldown gate.';

    public function handle(): int
    {
        Step::create([
            'class' => AnalyseBscsJob::class,
            'queue' => 'cronjobs',
            'arguments' => [],
            'block_uuid' => (string) Str::uuid(),
            'index' => 1,
        ]);

        $this->verboseInfo('Dispatched AnalyseBscsJob (BSCS cooldown gate).');

        return self::SUCCESS;
    }
}
