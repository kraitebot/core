<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns;

use Kraite\Core\Support\ModelCache;

/**
 * HasModelCache
 *
 * Provides model-scoped caching capabilities to any Eloquent model.
 * Cache keys are automatically scoped to the model instance.
 *
 * Usage:
 * ```
 * $result = $this->step->cache()->getOr('expensive_operation', function() {
 *     return $this->performExpensiveOperation();
 * });
 *
 * // With custom TTL
 * $result = $this->account->cache()->ttl(600)->getOr('balance', function() {
 *     return $this->apiQueryBalance();
 * });
 * ```
 */
trait HasModelCache
{
    /**
     * Get model cache instance for fluent operations.
     *
     * @param  int  $ttl  Default TTL in seconds (default: 5 minutes)
     */
    public function cache(int $ttl = 300): ModelCache
    {
        return new ModelCache($this, $ttl);
    }
}
