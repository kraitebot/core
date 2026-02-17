<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns;

use Kraite\Core\Abstracts\BaseModel;
use Kraite\Core\Observers\ModelLogObserver;

/**
 * Trait to enable Model Logging in Model Observers.
 *
 * Add this trait to any Observer to automatically log model changes.
 *
 * Usage:
 *   final class ExchangeSymbolObserver
 *   {
 *       use LogsModelChanges;
 *
 *       public function created(ExchangeSymbol $model): void
 *       {
 *           $this->logModelCreation($model);
 *       }
 *
 *       public function updated(ExchangeSymbol $model): void
 *       {
 *           $this->logModelUpdate($model);
 *       }
 *   }
 */
trait LogsModelChanges
{
    /**
     * Log model creation event.
     * Call this in the observer's created() method.
     */
    protected function logModelCreation(BaseModel $model): void
    {
        app(ModelLogObserver::class)->created($model);
    }

    /**
     * Log model update event.
     * Call this in the observer's updated() method.
     */
    protected function logModelUpdate(BaseModel $model): void
    {
        app(ModelLogObserver::class)->updated($model);
    }
}
