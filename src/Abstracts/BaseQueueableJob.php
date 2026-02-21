<?php

declare(strict_types=1);

namespace Kraite\Core\Abstracts;

use Kraite\Core\Exceptions\NonNotifiableException;
use StepDispatcher\Abstracts\BaseStepJob;
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
     * Log exception to the relatable model via modelLog().
     * This is Kraite-specific behavior — the generic BaseStepJob has a no-op.
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
    }
}
