<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Cronjobs;

use Kraite\Core\Jobs\Atomic\Position\PurgePositionTrailJob;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use StepDispatcher\Models\Step;
use StepDispatcher\Support\BaseCommand;
use StepDispatcher\Support\Steps;

/**
 * CronPurgePositionTrailsCommand
 *
 * Deferred-retention sweeper for the position breadcrumb janitor.
 *
 * With `kraite.positions.trail_retention_hours` > 0, PositionObserver
 * no longer fires PurgePositionTrailJob the instant a position closes —
 * the diagnostic trail (model_logs, api_request_logs, api_snapshots)
 * survives long enough for the nightly DB backup to capture it. This
 * sweeper then dispatches the janitor for every cleanly-closed position
 * whose `closed_at` has aged past the retention window and whose trail
 * still exists.
 *
 * Selection is trail-existence-driven, which makes the sweep idempotent:
 * once the janitor has reclaimed a position's trail, the position no
 * longer matches and is never re-dispatched. The `closed` status filter
 * preserves the janitor contract — `cancelled` / `failed` exits keep
 * their forensic trail forever.
 *
 * The janitor steps are dispatched under the `trading` prefix on the
 * `cronjobs` queue, mirroring PositionObserver's immediate-mode dispatch
 * exactly, so both paths produce the same observable step shape.
 */
final class CronPurgePositionTrailsCommand extends BaseCommand
{
    protected $signature = 'kraite:cron-purge-position-trails
                            {--hours-to-keep= : Retention window in hours (defaults to kraite.positions.trail_retention_hours)}
                            {--dry-run : List eligible positions without dispatching the janitor}
                            {--output : Display command output (silent by default)}';

    protected $description = 'Dispatch the breadcrumb janitor for closed positions older than the trail retention window.';

    public function handle(): int
    {
        $hours = $this->option('hours-to-keep') !== null
            ? max(0, (int) $this->option('hours-to-keep'))
            : max(0, (int) config('kraite.positions.trail_retention_hours', 0));

        $dryRun = (bool) $this->option('dry-run');

        $threshold = now()->subHours($hours);

        $this->verboseInfo(sprintf(
            'Sweeping trails of positions closed before %s (%d hour(s) retention)...',
            $threshold->toDateTimeString(),
            $hours
        ));

        $eligible = Position::query()
            ->where('status', 'closed')
            ->whereNotNull('closed_at')
            ->where('closed_at', '<=', $threshold)
            ->where(function ($query): void {
                // Trail-existence filter: a position whose janitor already
                // ran has no breadcrumb rows left and falls out of the
                // sweep. The position-scoped model_logs row is always
                // present pre-purge (the closed transition itself logs),
                // but the order- and api-scoped checks keep the filter
                // honest against partial trails.
                $query
                    ->whereExists(function ($sub): void {
                        $sub->from('model_logs')
                            ->where('loggable_type', Position::class)
                            ->whereColumn('loggable_id', 'positions.id');
                    })
                    ->orWhereExists(function ($sub): void {
                        $sub->from('api_request_logs')
                            ->where('relatable_type', Position::class)
                            ->whereColumn('relatable_id', 'positions.id');
                    })
                    ->orWhereExists(function ($sub): void {
                        $sub->from('api_snapshots')
                            ->where('responsable_type', Position::class)
                            ->whereColumn('responsable_id', 'positions.id');
                    })
                    ->orWhereExists(function ($sub): void {
                        $sub->from('model_logs')
                            ->join('orders', function ($join): void {
                                $join->on('orders.id', '=', 'model_logs.loggable_id')
                                    ->where('model_logs.loggable_type', Order::class);
                            })
                            ->whereColumn('orders.position_id', 'positions.id');
                    });
            })
            ->orderBy('id')
            ->get(['id', 'closed_at']);

        if ($eligible->isEmpty()) {
            $this->verboseInfo('No positions with reclaimable trails — nothing to sweep.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->verboseWarn('DRY RUN - no janitor steps will be dispatched');

            $eligible->each(function (Position $position): void {
                $this->verboseComment(sprintf(
                    '  → Position #%d (closed_at %s)',
                    $position->id,
                    $position->closed_at?->toDateTimeString() ?? 'NULL'
                ));
            });

            return self::SUCCESS;
        }

        $dispatched = 0;

        // Same prefix + queue shape as PositionObserver's immediate-mode
        // dispatch: the position lifecycle ran through trading_steps, so
        // the janitor must too — otherwise its steps deletion targets
        // the wrong table set.
        Steps::usingPrefix('trading', function () use ($eligible, &$dispatched): void {
            $eligible->each(function (Position $position) use (&$dispatched): void {
                Step::create([
                    'class' => PurgePositionTrailJob::class,
                    'queue' => 'cronjobs',
                    'arguments' => [
                        'positionId' => $position->id,
                    ],
                ]);

                $dispatched++;
            });
        });

        $this->verboseInfo(sprintf('Dispatched %d janitor step(s).', $dispatched));

        return self::SUCCESS;
    }
}
