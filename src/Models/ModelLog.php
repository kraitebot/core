<?php

declare(strict_types=1);

namespace Kraite\Core\Models;

use Kraite\Core\Abstracts\BaseModel;

/**
 * @property int $id
 * @property string $loggable_type
 * @property int $loggable_id
 * @property string|null $relatable_type
 * @property int|null $relatable_id
 * @property string $event_type
 * @property string|null $attribute_name
 * @property string|null $message
 * @property array|null $previous_value
 * @property array|null $new_value
 * @property array|null $metadata
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
final class ModelLog extends BaseModel
{
    /**
     * Global flag to enable/disable all model logging.
     * Set to false to disable logging (useful during seeding/migrations).
     */
    protected static bool $enabled = true;

    /**
     * Models that should have their changes automatically logged (allowlist).
     * Only these models will have audit logs created when they change.
     *
     * To add a new model: Add its fully qualified class name to this array.
     */
    protected static array $loggableModels = [
        Account::class,
        ExchangeSymbol::class,
        Order::class,
        Position::class,
        Symbol::class,
    ];

    protected array $skipsLogging = ['created_at', 'updated_at'];

    /**
     * Disable all model logging globally.
     */
    public static function disable(): void
    {
        self::$enabled = false;
    }

    /**
     * Enable all model logging globally.
     */
    public static function enable(): void
    {
        self::$enabled = true;
    }

    /**
     * Check if model logging is enabled.
     */
    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    /**
     * Check if a model should be logged based on the allowlist.
     *
     * @param BaseModel $model The model to check
     * @return bool True if the model should be logged, false otherwise
     */
    public static function shouldLog(BaseModel $model): bool
    {
        return in_array(get_class($model), self::$loggableModels);
    }

    protected function casts(): array
    {
        return [
            // Do NOT cast previous_value and new_value - store raw database values as-is
            // Casting causes type coercion (0 -> false, etc.) which creates confusion
            'metadata' => 'array',
        ];
    }

    /**
     * The model that was changed.
     */
    public function loggable()
    {
        return $this->morphTo();
    }

    /**
     * The model that triggered this change (optional).
     */
    public function relatable()
    {
        return $this->morphTo();
    }
}
