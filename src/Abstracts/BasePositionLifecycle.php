<?php

declare(strict_types=1);

namespace Kraite\Core\Abstracts;

use Kraite\Core\Models\Position;
use Kraite\Core\Support\Proxies\JobProxy;

/**
 * BasePositionLifecycle
 *
 * Abstract base class for position lifecycle orchestrators.
 * Lifecycles are NOT steps themselves - they create step(s) for atomic jobs.
 *
 * Usage:
 * ```php
 * $resolver = JobProxy::with($position->account);
 * $lifecycleClass = $resolver->resolve(Lifecycle\Position\DispatchPositionJob::class);
 * $lifecycle = new $lifecycleClass($position);
 * $nextIndex = $lifecycle->dispatch(
 *     blockUuid: $parentBlockUuid,
 *     startIndex: 1,
 *     workflowId: $workflowId
 * );
 * ```
 */
abstract class BasePositionLifecycle extends BaseLifecycle
{
    protected Position $position;

    public function __construct(Position $position)
    {
        $this->position = $position;
        $this->resolver = JobProxy::with($position->account);
    }
}
