<?php

declare(strict_types=1);

namespace Kraite\Core\Listeners;

use Kraite\Core\Jobs\Atomic\System\VerifyDispatcherGroupDrainedJob;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Support\MaintenanceMode;
use Kraite\Core\Support\NotificationService;
use StepDispatcher\Events\StaleStepsDetected;
use StepDispatcher\Models\Step;
use StepDispatcher\Support\RuntimeContext;
use StepDispatcher\Support\Steps;

/**
 * Kraite-side adapter for StepDispatcher's StaleStepsDetected event.
 *
 * Replaces the now-deleted CheckStaleDataCommand::reportStaleSteps() call
 * chain. The package fires a semantic event; this listener translates it
 * into the two existing Kraite notification canonicals so operators keep
 * seeing the same pushover/email format they had before.
 *
 * Only the two Dispatched-related reasons map to notifications today.
 * Running recovery and lock release fire the event too but are informational
 * — they'd be noisy as pushovers. If that changes, extend the match below.
 */
final class SendStaleStepsNotification
{
    public function handle(StaleStepsDetected $event): void
    {
        $canonical = match ($event->reason) {
            'stale_dispatched_steps_promoted' => 'stale_dispatched_steps_detected',
            'stale_dispatched_steps_still_stuck' => 'stale_priority_steps_detected',
            'group_no_progress' => 'group_no_progress_detected',
            default => null,
        };

        if ($canonical === null) {
            return;
        }

        // Group-progress wedge has a different payload shape — there's no
        // single "oldest step" because the symptom is per-group dispatcher
        // stall, not a specific stuck step. Surface group, pending count,
        // last terminal update, and threshold so the operator can jump
        // straight to the wedged group in the admin UI.
        if ($event->reason === 'group_no_progress') {
            // Suppress while steps:dispatch is intentionally paused for
            // THIS dispatcher's prefix. The watchdog runs as the
            // `steps:recover-stale --prefix=…` cron, so the active
            // RuntimeContext at this point reflects which dispatcher
            // emitted the event (trailing-underscore form: '' for the
            // default dispatcher, 'trading_' for trading). Reading the
            // pause state for THAT prefix means pausing default does
            // NOT silence trading wedge alerts and vice versa — only
            // the prefix actually frozen drops its noise.
            //
            // The all-scope pause (`pauseStepsDispatch(null)`) still
            // gates both — `isStepsDispatchPaused()` returns true when
            // either the all-scope OR the per-prefix flag is set.
            $watchdogPrefix = app(RuntimeContext::class)->current();
            if (MaintenanceMode::isStepsDispatchPaused($watchdogPrefix)) {
                return;
            }

            $context = $event->context;

            // Resolve the table set the wedged dispatcher actually owns from the
            // active prefix ('' -> steps / steps_dispatcher, 'trading_' ->
            // trading_steps / trading_steps_dispatcher). Threading these into the
            // message stops the resolution SQL hardcoding `FROM steps` when it
            // was a trading_steps group that stalled — the 2026-06-09 gamma case
            // sent the responder to the wrong table.
            $referenceData = [
                'count' => $event->count,
                'group' => $context['group'] ?? 'N/A',
                'pending_count' => $context['pending_count'] ?? $event->count,
                'last_terminal_update' => $context['last_terminal_update'] ?? 'never',
                'progress_threshold_seconds' => $context['progress_threshold_seconds'] ?? 0,
                'server' => $context['hostname'] ?? gethostname(),
                'steps_table' => $watchdogPrefix.'steps',
                'dispatcher_table' => $watchdogPrefix.'steps_dispatcher',
            ];

            // Throttle window is taken from the notification row's
            // cache_duration (not hardcoded here) so it can be tuned as
            // config — and, crucially, dropped to 0 when a Notification
            // Threshold is armed on this canonical, since the threshold
            // counts only post-throttle occurrences and a 600s throttle
            // would otherwise starve it. The row defaults to 600, so behaviour
            // is unchanged until the threshold is switched on.
            NotificationService::send(
                user: Kraite::admin(),
                canonical: $canonical,
                referenceData: $referenceData,
                cacheKeys: ['group' => $referenceData['group']],
            );

            // Non-critical follow-up: re-check in 15 minutes whether this
            // group drained on its own and tell the admin the outcome
            // (recovered vs still stalled). The recheck step lives in the
            // DEFAULT prefix — decoupled from the possibly-wedged prefix —
            // and carries the target prefix so the job probes the right
            // step table. Throttled by the same per-group cache window as
            // the alert above, so a flapping group spawns at most one
            // pending recheck per window.
            Steps::usingPrefix('', function () use ($referenceData, $watchdogPrefix): void {
                Step::create([
                    'class' => VerifyDispatcherGroupDrainedJob::class,
                    'queue' => 'cronjobs',
                    'arguments' => [
                        'group' => $referenceData['group'],
                        'prefix' => mb_rtrim($watchdogPrefix, '_'),
                        'pendingAtAlert' => (int) $referenceData['pending_count'],
                    ],
                    'dispatch_after' => now()->addMinutes(15),
                ]);
            });

            return;
        }

        $oldest = $event->oldestStep;

        $referenceData = [
            'count' => $event->count,
            'oldest_step_id' => $oldest?->id ?? 0,
            'oldest_canonical' => $oldest?->class ?? 'N/A',
            'oldest_group' => $oldest?->group ?? 'N/A',
            'oldest_index' => $oldest?->index ?? 0,
            'oldest_minutes_stuck' => $oldest?->updated_at
                ? (int) $oldest->updated_at->diffInMinutes(now())
                : 0,
            'oldest_dispatched_at' => $oldest?->updated_at?->toDateTimeString() ?? 'N/A',
            'oldest_parameters' => json_encode($oldest?->arguments ?? null, JSON_PRETTY_PRINT),
            'server' => $event->context['hostname'] ?? gethostname(),
            'promoted_count' => $event->promotedCount,
        ];

        NotificationService::send(
            user: Kraite::admin(),
            canonical: $canonical,
            referenceData: $referenceData,
            relatable: $oldest,
            duration: 60,
        );
    }
}
