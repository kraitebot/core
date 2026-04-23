<?php

declare(strict_types=1);

namespace Kraite\Core\Observers;

use Kraite\Core\Abstracts\BaseModel;
use Kraite\Core\Models\ModelLog;
use Kraite\Core\Support\ValueNormalizer;

final class ModelLogObserver
{
    /**
     * Global blacklist of attributes that should NEVER be logged.
     * These columns are automatically excluded for all models.
     */
    protected const GLOBAL_BLACKLIST = [
        'updated_at',
        'created_at',
        'deleted_at',
        'remember_token',
    ];

    /**
     * Cache for storing RAW attributes before save.
     * Keyed by spl_object_id() to avoid database column conflicts.
     */
    private static array $attributesCache = [];

    /**
     * Handle the "created" event - logs all initial attribute values.
     * Uses RAW values from getAttributes() (not cast).
     */
    public function created(BaseModel $model): void
    {
        // Skip if logging is globally disabled
        if (! ModelLog::isEnabled()) {
            return;
        }

        // Skip logging ModelLog itself to prevent infinite recursion
        if ($model instanceof ModelLog) {
            return;
        }

        // Check centralized allowlist
        if (! ModelLog::shouldLog($model)) {
            return;
        }

        $step = ModelLog::currentStep();

        // Use getAttributes() to get RAW database values (no casts)
        foreach ($model->getAttributes() as $attribute => $value) {
            // Check if should skip this attribute
            if ($this->shouldSkipLogging($model, $attribute, null, $value)) {
                continue;
            }

            ModelLog::create([
                'loggable_type' => get_class($model),
                'loggable_id' => $model->getKey(),
                'relatable_type' => $step ? get_class($step) : null,
                'relatable_id' => $step?->getKey(),
                'event_type' => 'attribute_created',
                'attribute_name' => $attribute,
                'previous_value' => null,
                'new_value' => $this->convertValueForStorage($value),
                'message' => "Attribute \"{$attribute}\" created with value: ".$this->formatValue($value),
            ]);
        }

        // Clear the cache to prevent saved() from running during creation
        $objectId = spl_object_id($model);
        unset(self::$attributesCache[$objectId]);
    }

    /**
     * Handle the "saving" event - cache RAW attribute values BEFORE database write.
     * This captures the state before Eloquent writes to database.
     */
    public function saving(BaseModel $model): void
    {
        // Skip if logging is globally disabled
        if (! ModelLog::isEnabled()) {
            return;
        }

        // Skip logging ModelLog itself to prevent infinite recursion
        if ($model instanceof ModelLog) {
            return;
        }

        // Check centralized allowlist
        if (! ModelLog::shouldLog($model)) {
            return;
        }

        // Cache the ORIGINAL RAW attributes from the database (before any changes)
        // We need to manually get raw values without casts for accurate comparison
        $original = [];
        foreach ($model->getOriginal() as $key => $value) {
            // getOriginal() might apply casts, so we need the raw DB value
            // Use getRawOriginal() if available, otherwise getOriginal()
            $original[$key] = $model->getRawOriginal($key);
        }

        self::$attributesCache[spl_object_id($model)] = $original;
    }

    /**
     * Handle the "saved" event - compare RAW values before and after save.
     * This ensures we compare actual database values (0 vs 0) not casted values (0 vs false).
     */
    public function saved(BaseModel $model): void
    {
        // Skip if logging is globally disabled
        if (! ModelLog::isEnabled()) {
            return;
        }

        // Skip logging ModelLog itself to prevent infinite recursion
        if ($model instanceof ModelLog) {
            return;
        }

        // Check centralized allowlist
        if (! ModelLog::shouldLog($model)) {
            return;
        }

        $objectId = spl_object_id($model);

        // No cached before state? Skip
        if (! isset(self::$attributesCache[$objectId])) {
            return;
        }

        // Get RAW attributes AFTER save (no casts applied)
        $rawAfterSave = $model->getAttributes();
        $rawBeforeSave = self::$attributesCache[$objectId];

        $step = ModelLog::currentStep();

        // Compare each attribute for changes (RAW vs RAW)
        foreach ($rawAfterSave as $attribute => $newRawValue) {
            $oldRawValue = $rawBeforeSave[$attribute] ?? null;

            // No change? Skip
            if ($oldRawValue === $newRawValue) {
                continue;
            }

            // Check if should skip this attribute (using RAW values)
            if ($this->shouldSkipLogging($model, $attribute, $oldRawValue, $newRawValue)) {
                continue;
            }

            ModelLog::create([
                'loggable_type' => get_class($model),
                'loggable_id' => $model->getKey(),
                'relatable_type' => $step ? get_class($step) : null,
                'relatable_id' => $step?->getKey(),
                'event_type' => 'attribute_changed',
                'attribute_name' => $attribute,
                'previous_value' => $this->convertValueForStorage($oldRawValue),
                'new_value' => $this->convertValueForStorage($newRawValue),
                'message' => $this->buildChangeMessage($attribute, $oldRawValue, $newRawValue),
            ]);
        }

        // Clean up cached attributes
        unset(self::$attributesCache[$objectId]);
    }

    /**
     * Compatibility method for LogsModelChanges trait.
     * This method is deprecated - logging is now handled automatically via saving() and saved() events.
     * Kept for backwards compatibility.
     *
     * @deprecated Use automatic logging instead. Remove manual logModelUpdate() calls.
     */
    public function updated(BaseModel $model): void
    {
        // Do nothing - saving() and saved() handle all logging automatically
        // This method exists only for backwards compatibility with existing observers
    }

    /**
     * Convert a value for storage in the LONGTEXT previous_value/new_value columns.
     * Arrays and objects are JSON encoded, all other types are stored as-is.
     */
    private function convertValueForStorage(mixed $value): mixed
    {
        // Arrays and objects need to be JSON encoded for storage in LONGTEXT columns
        if (is_array($value) || is_object($value)) {
            return json_encode($value);
        }

        // All other types (string, int, float, bool, null) can be stored directly
        return $value;
    }

    private function shouldSkipLogging(BaseModel $model, string $attribute, mixed $oldValue, mixed $newValue): bool
    {
        // Level 0: Check global blacklist (applies to ALL models)
        if (in_array($attribute, self::GLOBAL_BLACKLIST)) {
            return true; // Skip it
        }

        // Level 1: Check per-model static blacklist
        $skipsLogging = $model->skipsLogging ?? [];
        if (in_array($attribute, $skipsLogging)) {
            return true; // Skip it
        }

        // Level 2: Semantic equality check (handles type coercion for numerics/JSON)
        // This prevents false positives like "5.00000000" vs 5, or {"a":1,"b":2} vs {"b":2,"a":1}
        if (ValueNormalizer::areEqual($oldValue, $newValue)) {
            return true; // Values are semantically equal - skip logging
        }

        // Level 3: Check dynamic skipLogging() method (model-specific logic)
        if (method_exists($model, 'skipLogging')) {
            // If skipLogging() returns TRUE, we skip logging
            if ($model->skipLogging($attribute, $oldValue, $newValue) === true) {
                return true; // Skip it
            }
        }

        return false; // Don't skip = LOG IT
    }

    private function buildChangeMessage(string $attribute, mixed $oldValue, mixed $newValue): string
    {
        $old = $this->formatValue($oldValue);
        $new = $this->formatValue($newValue);

        if ($oldValue === null && $newValue !== null) {
            return "Attribute \"{$attribute}\" changed from null to {$new}";
        }

        if ($oldValue !== null && $newValue === null) {
            return "Attribute \"{$attribute}\" changed from {$old} to null";
        }

        return "Attribute \"{$attribute}\" changed from {$old} to {$new}";
    }

    private function formatValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        // Convert booleans to 0/1 to match raw database values
        // This ensures consistency since old values come from getRawOriginal() (raw)
        // but new values may come from getAttributes() which can be casted
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value) || is_object($value)) {
            return json_encode($value);
        }

        return (string) $value;
    }
}
