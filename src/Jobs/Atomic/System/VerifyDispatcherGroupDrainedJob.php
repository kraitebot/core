<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\System;

use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Support\NotificationService;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Pending;
use StepDispatcher\Support\Steps;

/**
 * VerifyDispatcherGroupDrainedJob (Atomic)
 *
 * Injected (with a +15min `dispatch_after`) whenever a CRITICAL
 * `group_no_progress_detected` stall alert fires. Fifteen minutes later it
 * re-checks whether the wedged dispatcher group drained on its own and
 * sends a NON-critical follow-up (`dispatcher_group_drain_recheck`) telling
 * the admin the outcome — so a transient backpressure wedge that self-heals
 * doesn't leave the operator wondering, and a genuine wedge gets a second,
 * explicit "still stalled" nudge.
 */
final class VerifyDispatcherGroupDrainedJob extends BaseQueueableJob
{
    public string $group;

    public string $prefix;

    public int $pendingAtAlert;

    public function __construct(string $group, string $prefix = '', int $pendingAtAlert = 0)
    {
        $this->group = $group;
        $this->prefix = $prefix;
        $this->pendingAtAlert = $pendingAtAlert;
    }

    public function relatable()
    {
        return Kraite::first();
    }

    public function compute()
    {
        // Count steps still Pending in the wedged group, scoped to the
        // dispatcher prefix that raised the original alert (default vs
        // trading live in separate step tables).
        $pendingNow = Steps::usingPrefix($this->prefix, function (): int {
            return Step::query()
                ->where('group', $this->group)
                ->where('state', Pending::class)
                ->count();
        });

        $drained = $pendingNow === 0;

        NotificationService::send(
            user: Kraite::admin(),
            canonical: 'dispatcher_group_drain_recheck',
            referenceData: [
                'group' => $this->group,
                'drained' => $drained,
                'pending_now' => $pendingNow,
                'pending_at_alert' => $this->pendingAtAlert,
                'server' => gethostname(),
            ],
            duration: 0,
        );

        return [
            'group' => $this->group,
            'prefix' => $this->prefix,
            'drained' => $drained,
            'pending_now' => $pendingNow,
            'pending_at_alert' => $this->pendingAtAlert,
        ];
    }
}
