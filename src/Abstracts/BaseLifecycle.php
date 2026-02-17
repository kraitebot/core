<?php

declare(strict_types=1);

namespace Kraite\Core\Abstracts;

use Kraite\Core\Support\Proxies\JobProxy;

/**
 * BaseLifecycle
 *
 * Abstract base class for all lifecycle orchestrators.
 * Lifecycles are NOT steps themselves - they create step(s) for atomic jobs.
 *
 * Subclasses provide their own model and resolver initialization:
 * - BasePositionLifecycle: Takes a Position, creates resolver from position->account
 * - BaseAccountLifecycle: Takes an Account, creates resolver from account
 * - BaseOrderLifecycle: Takes an Order, creates resolver from order->position->account (future)
 */
abstract class BaseLifecycle
{
    protected JobProxy $resolver;

    /**
     * Dispatch the lifecycle steps.
     *
     * @param  string  $blockUuid  The block UUID for step grouping
     * @param  int  $startIndex  The starting index for steps
     * @param  string|null  $workflowId  Optional workflow ID for grouping related steps
     * @return int The next available index (for chaining lifecycles)
     */
    abstract public function dispatch(
        string $blockUuid,
        int $startIndex,
        ?string $workflowId = null
    ): int;
}
