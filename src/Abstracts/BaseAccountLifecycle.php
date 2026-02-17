<?php

declare(strict_types=1);

namespace Kraite\Core\Abstracts;

use Kraite\Core\Models\Account;
use Kraite\Core\Support\Proxies\JobProxy;

/**
 * BaseAccountLifecycle
 *
 * Abstract base class for account lifecycle orchestrators.
 * Lifecycles are NOT steps themselves - they create step(s) for atomic jobs.
 *
 * Usage:
 * ```php
 * $resolver = JobProxy::with($account);
 * $lifecycleClass = $resolver->resolve(Lifecycle\Account\VerifyMinAccountBalanceJob::class);
 * $lifecycle = new $lifecycleClass($account);
 * $nextIndex = $lifecycle->dispatch(
 *     blockUuid: $parentBlockUuid,
 *     startIndex: 1,
 *     workflowId: $workflowId
 * );
 * ```
 */
abstract class BaseAccountLifecycle extends BaseLifecycle
{
    protected Account $account;

    public function __construct(Account $account)
    {
        $this->account = $account;
        $this->resolver = JobProxy::with($account);
    }
}
