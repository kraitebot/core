<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Position;

use Illuminate\Support\Facades\DB;
use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;

/**
 * PurgePositionTrailJob (Atomic)
 *
 * Janitor — wipes the diagnostic breadcrumb trail for a position whose
 * lifecycle ended cleanly (status transitioned to `closed`). For
 * non-clean exits (`cancelled`, `failed`) the trail is preserved for
 * forensic review, so this job is dispatched by PositionObserver only
 * on the `closed` transition.
 *
 * Targets every breadcrumb table whose rows are tied to the position
 * (or to one of its orders) via Laravel's polymorphic columns:
 *
 *   - model_logs       (loggable_* + relatable_*)
 *   - api_request_logs (relatable_*)
 *   - api_snapshots    (responsable_*)
 *   - steps            (relatable_*)
 *   - steps_archive    (relatable_*)
 *
 * The position row itself and every Order row stay untouched — those
 * are the position's permanent record (entry price, fill quantity,
 * close PnL); the breadcrumbs are diagnostic noise that only matters
 * when something went wrong.
 *
 * The janitor's own running step row is excluded from the steps
 * deletion so it can complete + be archived by the regular
 * `steps:archive` cron + drop via the 5-day `steps:purge --only-archive`
 * window. Without the exclusion the job would delete itself mid-flight.
 *
 * Single DB transaction so a mid-job failure leaves the breadcrumb
 * tables either fully-purged or fully-intact, never half-cleared.
 *
 * Idempotent — re-running on the same position id is a benign no-op
 * (the second run finds zero rows to delete and exits with all-zero
 * counts).
 */
final class PurgePositionTrailJob extends BaseQueueableJob
{
    public Position $position;

    public function __construct(int $positionId)
    {
        $this->position = Position::findOrFail($positionId);
    }

    public function relatable()
    {
        return $this->position;
    }

    public function compute(): array
    {
        $positionId = $this->position->id;
        $orderIds = Order::query()
            ->where('position_id', $positionId)
            ->pluck('id')
            ->all();

        $positionMorph = Position::class;
        $orderMorph = Order::class;
        $currentStepId = $this->step->id ?? 0;

        $deleted = DB::transaction(function () use (
            $positionId,
            $orderIds,
            $positionMorph,
            $orderMorph,
            $currentStepId,
        ): array {
            $modelLogs = DB::table('model_logs')
                ->where(function ($query) use ($positionId, $orderIds, $positionMorph, $orderMorph): void {
                    $query
                        ->where(function ($inner) use ($positionId, $positionMorph): void {
                            $inner->where('loggable_type', $positionMorph)
                                ->where('loggable_id', $positionId);
                        })
                        ->orWhere(function ($inner) use ($positionId, $positionMorph): void {
                            $inner->where('relatable_type', $positionMorph)
                                ->where('relatable_id', $positionId);
                        });

                    if ($orderIds !== []) {
                        $query->orWhere(function ($inner) use ($orderIds, $orderMorph): void {
                            $inner->where('loggable_type', $orderMorph)
                                ->whereIn('loggable_id', $orderIds);
                        });
                    }
                })
                ->delete();

            $apiRequestLogs = DB::table('api_request_logs')
                ->where(function ($query) use ($positionId, $orderIds, $positionMorph, $orderMorph): void {
                    $query
                        ->where(function ($inner) use ($positionId, $positionMorph): void {
                            $inner->where('relatable_type', $positionMorph)
                                ->where('relatable_id', $positionId);
                        });

                    if ($orderIds !== []) {
                        $query->orWhere(function ($inner) use ($orderIds, $orderMorph): void {
                            $inner->where('relatable_type', $orderMorph)
                                ->whereIn('relatable_id', $orderIds);
                        });
                    }
                })
                ->delete();

            $apiSnapshots = DB::table('api_snapshots')
                ->where('responsable_type', $positionMorph)
                ->where('responsable_id', $positionId)
                ->delete();

            // Exclude the running step row from the steps deletion so the
            // janitor can complete + be archived by the regular steps
            // pipeline. Without this guard the job would delete itself
            // mid-flight.
            $steps = DB::table('steps')
                ->where('relatable_type', $positionMorph)
                ->where('relatable_id', $positionId)
                ->where('id', '!=', $currentStepId)
                ->delete();

            $stepsArchive = DB::table('steps_archive')
                ->where('relatable_type', $positionMorph)
                ->where('relatable_id', $positionId)
                ->delete();

            return [
                'model_logs' => $modelLogs,
                'api_request_logs' => $apiRequestLogs,
                'api_snapshots' => $apiSnapshots,
                'steps' => $steps,
                'steps_archive' => $stepsArchive,
            ];
        });

        return [
            'position_id' => $positionId,
            'orders_scanned' => count($orderIds),
            'deleted' => $deleted,
        ];
    }
}
