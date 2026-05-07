<?php

declare(strict_types=1);

namespace Kraite\Core\Observers;

use Illuminate\Support\Str;
use Kraite\Core\Jobs\Atomic\Position\PurgePositionTrailJob;
use Kraite\Core\Models\Position;
use StepDispatcher\Models\Step;

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

        Step::create([
            'class' => PurgePositionTrailJob::class,
            'queue' => 'cronjobs',
            'arguments' => [
                'positionId' => $model->id,
            ],
        ]);
    }
}
