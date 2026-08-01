<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns\BaseModel;

use Illuminate\Database\Eloquent\Model;
use Kraite\Core\Models\AppLog;
use Kraite\Core\Models\ModelLog;

/**
 * Trait for model logging functionality on BaseModel.
 *
 * This trait provides:
 * - Default blacklist for timestamp columns
 * - skipLogging() method for conditional filtering
 * - modelLog() method for technical audit logging
 * - appLog() method for user-facing business timeline logging
 */
trait LogsApplicationEvents
{
    /**
     * Default blacklist - skip timestamp columns by default for model logging.
     */
    protected array $skipsLogging = ['created_at', 'updated_at', 'deleted_at'];

    /**
     * Determine if logging should be skipped for a specific attribute change.
     * Override this method in child models for custom logic.
     *
     * Works for BOTH created() and updated() events.
     *
     * @return bool True = skip logging, False = log it
     */
    public function skipLogging(string $attribute, mixed $oldValue, mixed $newValue): bool
    {
        return false; // By default, don't skip (will be logged)
    }

    /**
     * Manually log a model event for this model.
     *
     * @param  string  $eventType  The type of event (e.g., 'job_failed', 'order_filled')
     * @param  array  $metadata  Additional data to store with the log
     * @param  Model|null  $relatable  The model that triggered this event (optional)
     * @param  string|null  $message  Optional human-readable message
     */
    public function modelLog(
        string $eventType,
        array $metadata = [],
        ?Model $relatable = null,
        ?string $message = null
    ): ?ModelLog {
        // Skip if logging is globally disabled
        if (! ModelLog::isEnabled()) {
            return null;
        }

        return ModelLog::create([
            'loggable_type' => static::class,
            'loggable_id' => $this->getKey(),
            'relatable_type' => $relatable ? get_class($relatable) : null,
            'relatable_id' => $relatable?->getKey(),
            'event_type' => $eventType,
            'metadata' => $metadata,
            'message' => $message,
        ]);
    }

    /**
     * Log a user-facing business event for this model.
     * These appear in the UI timeline — keep messages human-readable.
     */
    public function appLog(
        string $event,
        string $message,
        string $severity = 'info',
        array $metadata = []
    ): ?AppLog {
        if (! AppLog::isEnabled()) {
            return null;
        }

        return AppLog::create([
            'loggable_type' => static::class,
            'loggable_id' => $this->getKey(),
            'event' => $event,
            'message' => $message,
            'severity' => $severity,
            'metadata' => $metadata,
        ]);
    }
}
