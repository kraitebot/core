<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Cronjobs;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\Position;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Failed;
use StepDispatcher\Support\BaseCommand;
use StepDispatcher\Support\Steps;

/**
 * Persist the compact operational evidence previously produced by the
 * standalone production Bash monitors. Safety decisions remain in the
 * dedicated system-health and drift commands; this command is telemetry only.
 */
final class RecordOperationalSnapshotCommand extends BaseCommand
{
    private const WINDOW_MINUTES = 30;

    protected $signature = 'kraite:cron-record-operational-snapshot
                            {--output : Display the JSON snapshot after recording it}';

    protected $description = 'Record recent workflow failures and opened position/order counts in the jobs log.';

    public function handle(): int
    {
        $cutoff = now()->subMinutes(self::WINDOW_MINUTES);
        $openedStatuses = (new Position)->openedStatuses();

        $failedSteps = [
            'default' => $this->failedStepsByClass('', $cutoff),
            'trading' => $this->failedStepsByClass('trading', $cutoff),
        ];

        $engine = Kraite::query()
            ->select(['allow_opening_positions', 'is_cooling_down'])
            ->first();

        $snapshot = [
            'recorded_at' => now('UTC')->toIso8601String(),
            'window_minutes' => self::WINDOW_MINUTES,
            'mode' => $failedSteps['default'] !== [] || $failedSteps['trading'] !== []
                ? 'failed_steps'
                : 'positions_orders',
            'maintenance' => app()->isDownForMaintenance(),
            'failed_steps_30m' => $failedSteps,
            'opened_positions' => DB::table('positions')
                ->selectRaw('status, COUNT(*) AS total')
                ->whereIn('status', $openedStatuses)
                ->groupBy('status')
                ->orderBy('status')
                ->pluck('total', 'status')
                ->map(static fn (mixed $total): int => (int) $total)
                ->all(),
            'orders_for_opened_positions' => DB::table('orders')
                ->join('positions', 'positions.id', '=', 'orders.position_id')
                ->selectRaw('orders.status, COUNT(*) AS total')
                ->whereIn('positions.status', $openedStatuses)
                ->groupBy('orders.status')
                ->orderBy('orders.status')
                ->pluck('total', 'orders.status')
                ->map(static fn (mixed $total): int => (int) $total)
                ->all(),
            'failed_jobs_30m' => DB::table('failed_jobs')
                ->where('failed_at', '>=', $cutoff)
                ->count(),
            'gate' => $engine === null ? null : [
                'allow_opening_positions' => $engine->allow_opening_positions,
                'is_cooling_down' => $engine->is_cooling_down,
            ],
        ];

        Log::channel('jobs')->info('[OPERATIONAL-SNAPSHOT]', $snapshot);

        if ($this->option('output')) {
            $this->line(json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, int>
     */
    private function failedStepsByClass(string $prefix, CarbonInterface $cutoff): array
    {
        return Steps::usingPrefix($prefix, static fn (): array => Step::query()
            ->selectRaw('class, COUNT(*) AS total')
            ->where('state', Failed::class)
            ->where('updated_at', '>=', $cutoff)
            ->groupBy('class')
            ->orderBy('class')
            ->pluck('total', 'class')
            ->map(static fn (mixed $total): int => (int) $total)
            ->all());
    }
}
