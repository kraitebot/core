<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Cronjobs;

use Illuminate\Support\Facades\DB;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Support\NotificationService;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Dispatched;
use StepDispatcher\Support\BaseCommand;

/**
 * CheckStaleDataCommand
 *
 * Monitors for stuck steps in Dispatched state and implements self-healing.
 *
 * Note: WebSocket heartbeat monitoring was removed when WebSocket price streaming
 * was replaced with REST-based kline fetching via FetchKlinesJob.
 */
final class CheckStaleDataCommand extends BaseCommand
{
    protected $signature = 'kraite:cron-check-stale-data
                            {--step-threshold=300 : Maximum seconds in Dispatched state before flagging as stale}
                            {--lock-threshold=30 : Maximum seconds a dispatcher lock can be held before auto-release}
                            {--output : Display command output (silent by default)}';

    protected $description = 'Check for stale data: steps stuck in Dispatched state';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->verboseInfo('Starting stale data monitoring...');

        // Self-healing: release any stuck dispatcher locks before other checks
        $lockThreshold = (int) $this->option('lock-threshold');
        $this->releaseStaleDispatcherLocks($lockThreshold);

        $stepThreshold = (int) $this->option('step-threshold');

        // Check for stale dispatched steps
        $staleSteps = $this->checkStaleSteps($stepThreshold);

        if ($staleSteps === null) {
            $this->verboseInfo('✓ All stale data checks passed.');

            return self::SUCCESS;
        }

        $this->reportStaleSteps($staleSteps, $stepThreshold);

        // Return SUCCESS even when issues are detected - the command handles them
        // via self-healing and notifications. Returning FAILURE would cause the
        // scheduler to log errors for expected operational situations.
        return self::SUCCESS;
    }

    /**
     * Check for steps stuck in Dispatched state.
     *
     * @return array{count: int, already_promoted_count: int, oldest_step: array{id: int, canonical: string|null, group: string|null, index: int|null, minutes_stuck: int, dispatched_at: string|null, parameters: mixed}}|null
     */
    public function checkStaleSteps(int $thresholdSeconds): ?array
    {
        $staleThreshold = now()->subSeconds($thresholdSeconds);

        $staleCount = Step::query()
            ->where('state', Dispatched::class)
            ->where('updated_at', '<', $staleThreshold)
            ->count();

        if ($staleCount === 0) {
            return null;
        }

        // Check if any stale steps are already promoted (priority queue + high priority)
        // These are steps that were already promoted by a previous run but still stuck
        $alreadyPromotedCount = Step::query()
            ->where('state', Dispatched::class)
            ->where('updated_at', '<', $staleThreshold)
            ->where('queue', 'priority')
            ->where('priority', 'high')
            ->count();

        // Get oldest stuck step as example
        $oldestStep = Step::query()
            ->where('state', Dispatched::class)
            ->where('updated_at', '<', $staleThreshold)
            ->orderBy('updated_at', 'asc')
            ->first();

        if (! $oldestStep) {
            return null;
        }

        /** @var Step $oldestStep */
        $minutesStuck = (int) $oldestStep->updated_at->diffInMinutes(now());

        return [
            'count' => $staleCount,
            'already_promoted_count' => $alreadyPromotedCount,
            'oldest_step' => [
                'id' => $oldestStep->id,
                'canonical' => $oldestStep->class,
                'group' => $oldestStep->group,
                'index' => $oldestStep->index,
                'parameters' => $oldestStep->arguments,
                'minutes_stuck' => $minutesStuck,
                'dispatched_at' => $oldestStep->updated_at->toDateTimeString(),
            ],
        ];
    }

    /**
     * Release dispatcher locks that have been held for too long.
     * This is a self-healing mechanism to prevent stuck groups from blocking step processing.
     */
    public function releaseStaleDispatcherLocks(int $thresholdSeconds): void
    {
        $staleThreshold = now()->subSeconds($thresholdSeconds);

        DB::table('steps_dispatcher')
            ->where('can_dispatch', false)
            ->where('updated_at', '<', $staleThreshold)
            ->update([
                'can_dispatch' => true,
                'current_tick_id' => null,
                'updated_at' => now(),
            ]);
    }

    /**
     * Report stale steps.
     *
     * @param  array{count: int, already_promoted_count: int, oldest_step: array{id: int, canonical: string|null, group: string|null, index: int|null, minutes_stuck: int, dispatched_at: string|null, parameters: mixed}}  $staleSteps
     */
    public function reportStaleSteps(array $staleSteps, int $threshold): void
    {
        $this->verboseNewLine();
        $this->verboseError('✗ Stale data issues detected!');
        $this->verboseNewLine();
        $this->verboseWarn('Stale Dispatched Steps Detected (threshold: '.$threshold.' seconds):');
        $this->verboseNewLine();

        $count = $staleSteps['count'];
        $alreadyPromotedCount = $staleSteps['already_promoted_count'];
        $oldest = $staleSteps['oldest_step'];

        $tableData = [
            ['Total Stuck Steps', (string) $count],
            ['Already Promoted', (string) $alreadyPromotedCount],
            ['Oldest Step ID', (string) $oldest['id']],
            ['Class (Canonical)', (string) ($oldest['canonical'] ?? 'N/A')],
            ['Group', (string) ($oldest['group'] ?? 'N/A')],
            ['Index', (string) ($oldest['index'] ?? 'N/A')],
            ['Minutes Stuck', (string) $oldest['minutes_stuck']],
            ['Dispatched At', $oldest['dispatched_at'] ?? 'N/A'],
        ];

        $this->verboseTable(['Metric', 'Value'], $tableData);
        $this->verboseNewLine();

        // Get the oldest step model for relatable context
        $oldestStepModel = Step::query()
            ->where('state', Dispatched::class)
            ->where('updated_at', '<', now()->subSeconds($threshold))
            ->orderBy('updated_at', 'asc')
            ->first();

        // If ALL stale steps are already promoted, this is CRITICAL - self-healing failed
        if ($alreadyPromotedCount > 0 && $alreadyPromotedCount === $count) {
            $this->verboseError("→ CRITICAL: {$alreadyPromotedCount} step(s) still stuck after promotion - manual intervention required!");

            NotificationService::send(
                user: Kraite::admin(),
                canonical: 'stale_priority_steps_detected',
                referenceData: [
                    'count' => $count,
                    'oldest_step_id' => $oldest['id'],
                    'oldest_canonical' => $oldest['canonical'],
                    'oldest_group' => $oldest['group'],
                    'oldest_index' => $oldest['index'],
                    'oldest_minutes_stuck' => $oldest['minutes_stuck'],
                    'oldest_dispatched_at' => $oldest['dispatched_at'] ?? 'N/A',
                    'oldest_parameters' => json_encode($oldest['parameters'], JSON_PRETTY_PRINT),
                    'server' => gethostname(),
                ],
                relatable: $oldestStepModel,
                duration: 60
            );

            return;
        }

        // Self-healing: Promote stale steps to high priority and priority queue
        // Only promote steps that are not already promoted
        $promotedCount = Step::query()
            ->where('state', Dispatched::class)
            ->where('updated_at', '<', now()->subSeconds($threshold))
            ->where(static function ($query): void {
                $query->where('queue', '!=', 'priority')
                    ->orWhere('priority', '!=', 'high');
            })
            ->update([
                'priority' => 'high',
                'queue' => 'priority',
            ]);

        $this->verboseInfo("→ Self-healing: Promoted {$promotedCount} stale step(s) to high priority queue");

        NotificationService::send(
            user: Kraite::admin(),
            canonical: 'stale_dispatched_steps_detected',
            referenceData: [
                'count' => $count,
                'oldest_step_id' => $oldest['id'],
                'oldest_canonical' => $oldest['canonical'],
                'oldest_group' => $oldest['group'],
                'oldest_index' => $oldest['index'],
                'oldest_minutes_stuck' => $oldest['minutes_stuck'],
                'oldest_dispatched_at' => $oldest['dispatched_at'] ?? 'N/A',
                'oldest_parameters' => json_encode($oldest['parameters'], JSON_PRETTY_PRINT),
                'server' => gethostname(),
                'promoted_count' => $promotedCount,
            ],
            relatable: $oldestStepModel,
            duration: 60
        );
    }
}
