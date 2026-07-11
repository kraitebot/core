<?php

declare(strict_types=1);

namespace Kraite\Core\Abstracts;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Kraite\Core\Exceptions\NonNotifiableException;
use Kraite\Core\Models\ModelLog;
use StepDispatcher\Abstracts\BaseStepJob;
use StepDispatcher\Models\Step;
use StepDispatcher\Support\ExceptionParser;
use Throwable;

/*
 * BaseQueueableJob
 *
 * Kraite-specific extension of BaseStepJob.
 * Adds project-specific defaults ($retries, $timeout) and hooks:
 * - shouldExitEarly() throws NonNotifiableException (not RuntimeException)
 * - onExceptionLogged() logs to relatable model via modelLog()
 */
abstract class BaseQueueableJob extends BaseStepJob
{
    // Max retries for a "always pending" job. Then update to "failed".
    public int $retries = 20;

    // Laravel job timeout configuration.
    // Set to 0 to rely on Horizon's supervisor timeout instead of job-level timeout.
    // This ensures Laravel properly recognizes Horizon timeouts and calls failed() method.
    public $timeout = 0;

    /**
     * Register the active Step on ModelLog as soon as the framework hands us
     * a prepared step. Any model change that happens from here until the
     * queue worker clears the context (see CoreServiceProvider's
     * JobProcessed / JobFailed listeners) is stamped with this step via
     * ModelLogObserver, so audit rows carry causality.
     */
    protected function prepareJobExecution(): bool
    {
        $prepared = parent::prepareJobExecution();

        if ($prepared) {
            ModelLog::setCurrentStep($this->step);
        }

        return $prepared;
    }

    /**
     * Override to throw NonNotifiableException instead of RuntimeException
     * when startOrFail() returns false.
     */
    protected function shouldExitEarly(): bool
    {
        if (! $this->shouldStartOrStop()) {
            $this->stopJob();

            return true;
        }

        if (! $this->shouldStartOrFail()) {
            throw new NonNotifiableException("startOrFail() returned false for Step ID {$this->step->id}");
        }

        if (! $this->shouldStartOrSkip()) {
            $this->skipJob();

            return true;
        }

        if (! $this->shouldStartOrRetry()) {
            $this->retryJob();

            return true;
        }

        // Check max retries after business logic checks
        $this->checkMaxRetries();

        return false;
    }

    /**
     * Build an orchestrator's child chain exactly once, atomically.
     *
     * Orchestrators persist their child_block_uuid the moment they elect to
     * parent mode, then insert child steps one by one. Two retry vectors can
     * rerun compute() against that same block: a transient DB error mid-build
     * (classified retryable → parent back to Pending) and steps:recover-stale
     * reclaiming a zombie parent whose tree already settled. Without
     * protection, the rerun re-inserts children at the same (block_uuid,
     * index) — there is no unique constraint — and BOTH copies become
     * dispatchable: duplicate status flips at best, duplicate exchange
     * placements (double market entry) at worst.
     *
     * Two layers, each covering the vector the other cannot:
     *   - The transaction makes a partial build vanish on failure, so a
     *     retry rebuilds from zero instead of appending to half a chain.
     *   - The populated-block guard makes a rerun against a fully-committed
     *     chain a no-op (the settled-tree recover-stale case).
     *
     * Returns true when this call built the chain, false when a prior run
     * already had — callers should return their normal response either way
     * and let the parent await its (already existing) children.
     */
    protected function buildChildChainOnce(Closure $builder): bool
    {
        $blockUuid = $this->step->child_block_uuid ?? $this->step->makeItAParent();

        $alreadyBuilt = Step::query()
            ->where('block_uuid', $blockUuid)
            ->exists();

        if ($alreadyBuilt) {
            Step::log($this->step->id, 'lifecycle', 'Retry detected — child block already populated, skipping chain build.');

            return false;
        }

        DB::transaction(function () use ($builder, $blockUuid): void {
            $builder($blockUuid);
        });

        return true;
    }

    /**
     * Belt-and-suspenders clear for the process-scoped step context.
     *
     * The primary clear runs via Queue::after / Queue::failing listeners
     * registered in CoreServiceProvider — those fire once per queued job
     * and cover 100% of real Horizon / worker paths. This destructor
     * handles synchronous execution paths where queue events never fire
     * (unit tests invoking handle() directly, future dispatchSync uses,
     * Queue::fake interactions). The identity guard ensures we only null
     * the static when THIS job's step is the registered one — without
     * it, a destructor fired late during GC could wipe out a subsequent
     * job's context.
     */
    public function __destruct()
    {
        if (! isset($this->step)) {
            return;
        }

        $current = ModelLog::currentStep();

        if ($current !== null && $current->getKey() === $this->step->getKey()) {
            ModelLog::setCurrentStep(null);
        }
    }

    /**
     * Log exception to the relatable model via modelLog().
     * This is Kraite-specific behavior — the generic BaseStepJob has a no-op.
     *
     * Failure-containment contract: this method runs INSIDE the outer
     * exception handler (handleException → onExceptionLogged → resolveException
     * → reportAndFail). A throw from modelLog() here would hijack the outer
     * flow, skip reportAndFail(), and leave the step in an incoherent state
     * with the original exception's diagnostics shadowed by the audit-log
     * failure. Any inner failure is swallowed and surfaced via Log::warning,
     * mirroring the same pattern used in NotificationService::sendToSpecificUser.
     */
    protected function onExceptionLogged(Throwable $e): void
    {
        // Guard against accessing step before initialization
        if (! isset($this->step)) {
            return;
        }

        // Get the relatable model from the step
        $relatable = $this->step->relatable;

        // Only log if relatable exists and has the modelLog method
        if (! $relatable || ! method_exists($relatable, 'modelLog')) {
            return;
        }

        try {
            $parser = ExceptionParser::with($e);

            // Create ModelLog entry on the relatable model
            $relatable->modelLog(
                eventType: 'step_failed',
                metadata: [
                    'exception_class' => get_class($e),
                    'exception_message' => $parser->friendlyMessage(),
                ],
                relatable: $this->step,
                message: $parser->friendlyMessage()
            );
        } catch (Throwable $logException) {
            Log::warning('[BaseQueueableJob] onExceptionLogged failed; swallowed to protect the outer exception handler', [
                'step_id' => $this->step->id ?? null,
                'relatable_class' => get_class($relatable),
                'original_exception_class' => get_class($e),
                'original_exception_message' => $e->getMessage(),
                'log_exception_class' => get_class($logException),
                'log_exception_message' => $logException->getMessage(),
            ]);
        }
    }
}
