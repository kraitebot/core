<?php

declare(strict_types=1);

namespace Kraite\Core\Observers;

use Illuminate\Support\Str;
use Kraite\Core\Jobs\Atomic\Position\PurgePositionTrailJob;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\Position;
use StepDispatcher\Models\Step;
use StepDispatcher\Support\Steps;

final class PositionObserver
{
    public function creating(Position $model): void
    {
        $model->uuid ??= Str::uuid()->toString();
    }

    /**
     * Trigger the breadcrumb janitor when a position completes cleanly.
     *
     * Only the `closed` transition fires the purge — `cancelled` and
     * `failed` exits keep their full diagnostic trail (steps, model
     * logs, api request logs, api snapshots) so the operator has every
     * row available when reconciling a non-happy-path outcome.
     */
    public function updated(Position $model): void
    {
        if (! $model->wasChanged('status')) {
            return;
        }

        if ($model->status !== 'closed') {
            return;
        }

        // Deferred retention: with a configured trail retention window the
        // immediate purge is skipped — the diagnostic trail survives long
        // enough for the nightly DB backup to capture it, and the
        // `kraite:cron-purge-position-trails` sweeper dispatches the
        // janitor once `closed_at` ages past the window. Retention 0
        // keeps the original purge-on-close behavior.
        if (Kraite::trailRetentionHours() > 0) {
            return;
        }

        // The position lifecycle ran through `trading_steps`, so the
        // janitor that purges its breadcrumb trail must also run under
        // the trading prefix — otherwise its `DELETE FROM steps WHERE
        // workflow_id=…` query targets the wrong table and the trail
        // is never reclaimed.
        Steps::usingPrefix('trading', function () use ($model): void {
            Step::create([
                'class' => PurgePositionTrailJob::class,
                'queue' => 'cronjobs',
                'arguments' => [
                    'positionId' => $model->id,
                ],
            ]);
        });
    }
}
