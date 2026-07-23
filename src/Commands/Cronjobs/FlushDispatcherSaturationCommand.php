<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Cronjobs;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Kraite\Core\Models\StepsDispatcherSaturation;
use StepDispatcher\Support\BaseCommand;
use StepDispatcher\Support\RuntimeContext;
use Throwable;

/**
 * FlushDispatcherSaturationCommand
 *
 * Pulls the per-tick Redis counters written by the dispatcher's
 * `recordTickMetrics` into a persistent per-minute aggregate row in
 * `steps_dispatcher_saturation`. The dashboard reads from MySQL; the
 * dispatcher hot path stays Redis-only.
 *
 * Bucket model:
 *   - Each tick increments four keys under
 *     `dispatcher:saturation:{group}:{YYYY-MM-DD-HH-MM}:*`.
 *   - This command runs every minute and consumes the *previous*
 *     completed minute, never the current in-progress one (avoids
 *     racing with in-flight dispatcher ticks).
 *   - After flush, the consumed Redis keys are deleted so they don't
 *     accumulate. Their natural TTL is 90s as a backstop.
 *
 * Saturation %:
 *   ticks_capped_with_leftover / ticks_observed × 100
 *
 *   100% = every tick this minute hit the cap AND had more Pending
 *          work waiting. Unambiguous "more dispatcher capacity
 *          would help".
 *   <100% = the cap is not the bottleneck this minute. Adding groups
 *          will not move the needle; look downstream.
 */
final class FlushDispatcherSaturationCommand extends BaseCommand
{
    /**
     * The 10 dispatcher groups we record. Ordered alphabetically to
     * keep the dashboard layout deterministic across runs.
     */
    private const GROUPS = ['alpha', 'beta', 'gamma', 'delta', 'epsilon', 'zeta', 'eta', 'theta', 'iota', 'kappa'];

    protected $signature = 'kraite:cron-flush-dispatcher-saturation';

    protected $description = 'Flush per-tick dispatcher saturation counters from Redis into the persistent steps_dispatcher_saturation table.';

    public function handle(): int
    {
        // Always flush the *previous* completed minute so we never read
        // a bucket that the dispatcher is still writing into.
        $bucketAt = Carbon::now('UTC')->startOfMinute()->subMinute();
        $bucketKey = $bucketAt->format('Y-m-d-H-i');
        $runtimePrefix = app(RuntimeContext::class)->current();

        foreach (self::GROUPS as $group) {
            $metricGroup = $runtimePrefix.$group;

            try {
                $this->flushBucket($metricGroup, $bucketAt, $bucketKey);
            } catch (Throwable $e) {
                Log::channel('jobs')->error('[SATURATION-FLUSH] failed for group', [
                    'group' => $metricGroup,
                    'bucket' => $bucketKey,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return self::SUCCESS;
    }

    private function flushBucket(string $group, Carbon $bucketAt, string $bucketKey): void
    {
        $prefix = "dispatcher:saturation:{$group}:{$bucketKey}";

        $observed = (int) (Cache::get("{$prefix}:ticks_observed") ?? 0);

        // No ticks observed this bucket → nothing to persist. Common
        // for groups that briefly had nothing to do.
        if ($observed === 0) {
            return;
        }

        $capped = (int) (Cache::get("{$prefix}:ticks_capped") ?? 0);
        $cappedWithLeftover = (int) (Cache::get("{$prefix}:ticks_capped_with_leftover") ?? 0);
        $totalDispatched = (int) (Cache::get("{$prefix}:total_dispatched") ?? 0);
        $maxPendingAfter = (int) (Cache::get("{$prefix}:max_pending_after") ?? 0);

        StepsDispatcherSaturation::updateOrCreate(
            [
                'group' => $group,
                'bucket_started_at' => $bucketAt,
            ],
            [
                'ticks_observed' => $observed,
                'ticks_capped' => $capped,
                'ticks_capped_with_leftover' => $cappedWithLeftover,
                'total_dispatched' => $totalDispatched,
                'max_pending_after' => $maxPendingAfter,
            ]
        );

        // Drop the consumed counters. The dispatcher writes to a fresh
        // bucket key for the next minute, so leaving these would just
        // leak memory until their 90s TTL.
        Cache::forget("{$prefix}:ticks_observed");
        Cache::forget("{$prefix}:ticks_capped");
        Cache::forget("{$prefix}:ticks_capped_with_leftover");
        Cache::forget("{$prefix}:total_dispatched");
        Cache::forget("{$prefix}:max_pending_after");
    }
}
