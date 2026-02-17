<?php

declare(strict_types=1);

namespace Kraite\Core\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * ModelCache
 *
 * Provides a fluent caching interface scoped to a specific model instance.
 * Useful for caching expensive operations (API calls, calculations) that need
 * to be idempotent across job retries.
 *
 * Cache keys are scoped to the model: "{table}:{id}:cache:{canonical}"
 *
 * Example:
 * ```
 * $result = $this->step->cache()->getOr('place_order', function() {
 *     return $this->order->apiPlace();
 * });
 * ```
 */
final class ModelCache
{
    private Model $model;

    private int $ttl;

    public function __construct(Model $model, int $ttl = 300)
    {
        $this->model = $model;
        $this->ttl = $ttl;
    }

    /**
     * Get cached value or execute callback and cache result.
     *
     * @param  string  $canonical  Unique identifier for this cached operation
     * @param  Closure  $callback  The operation to cache
     * @return mixed The cached or fresh result
     */
    public function getOr(string $canonical, Closure $callback): mixed
    {
        $cacheKey = $this->buildKey($canonical);

        return cache()->remember($cacheKey, $this->ttl, $callback);
    }

    /**
     * Set custom TTL for subsequent operations.
     *
     * @param  int  $seconds  TTL in seconds
     */
    public function ttl(int $seconds): self
    {
        $this->ttl = $seconds;

        return $this;
    }

    /**
     * Remove value from cache.
     *
     * @param  string  $canonical  The operation to clear from cache
     */
    public function forget(string $canonical): void
    {
        $cacheKey = $this->buildKey($canonical);

        cache()->forget($cacheKey);
    }

    /**
     * Build cache key for this model and canonical.
     *
     * Format: "{table}:{id}:cache:{canonical}"
     */
    private function buildKey(string $canonical): string
    {
        return "{$this->model->getTable()}:{$this->model->id}:cache:{$canonical}";
    }
}
