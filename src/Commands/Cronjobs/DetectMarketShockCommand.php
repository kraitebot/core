<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Cronjobs;

use Illuminate\Support\Str;
use Kraite\Core\Jobs\Models\MarketRegime\DetectMarketShockJob;
use StepDispatcher\Models\Step;
use StepDispatcher\Support\BaseCommand;

/**
 * Fast cascade-in-progress detector. Reads recent 15m klines for
 * BTC + 4 reference alts every minute, arms the shared
 * `bscs_cooldown_until` column when MarketShockCircuitBreaker fires.
 *
 * Distinct from `kraite:cron-compute-market-regime` (slow, hourly,
 * setup fragility) and from `kraite:cron-analyse-bscs` (slow, hourly,
 * BSCS gate state machine). This is the *minute-level safety net*
 * that closes the hourly compute blind spot.
 *
 * @see DetectMarketShockJob
 * @see ~/docs/kraite/black-swan-logic.md (Phase 2.1A)
 */
final class DetectMarketShockCommand extends BaseCommand
{
    protected $signature = 'kraite:cron-detect-market-shock';

    protected $description = 'Detect market-wide cascade in progress and arm the shared BSCS cooldown.';

    public function handle(): int
    {
        Step::create([
            'class' => DetectMarketShockJob::class,
            'queue' => 'cronjobs',
            'arguments' => [],
            'block_uuid' => (string) Str::uuid(),
            'index' => 1,
        ]);

        $this->verboseInfo('Dispatched DetectMarketShockJob (cascade detector).');

        return self::SUCCESS;
    }
}
