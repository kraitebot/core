<?php

declare(strict_types=1);

namespace Kraite\Core\Listeners;

use Kraite\Core\Models\Kraite;
use Kraite\Core\Support\MaintenanceMode;
use Kraite\Core\Support\NotificationService;
use StepDispatcher\Events\StaleStepsDetected;
use StepDispatcher\Support\RuntimeContext;

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

            $referenceData = [
                'count' => $event->count,
                'group' => $context['group'] ?? 'N/A',
                'pending_count' => $context['pending_count'] ?? $event->count,
                'last_terminal_update' => $context['last_terminal_update'] ?? 'never',
                'progress_threshold_seconds' => $context['progress_threshold_seconds'] ?? 0,
                'server' => $context['hostname'] ?? gethostname(),
            ];

            NotificationService::send(
                user: Kraite::admin(),
                canonical: $canonical,
                referenceData: $referenceData,
                duration: 600,
                cacheKeys: ['group' => $referenceData['group']],
            );

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
