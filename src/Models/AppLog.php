<?php

declare(strict_types=1);

namespace Kraite\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * User-facing business activity log.
 *
 * Unlike ModelLog (technical audit trail), AppLog stores human-readable
 * business milestones visible to end users in the UI timeline.
 *
 * @property int $id
 * @property string $loggable_type
 * @property int $loggable_id
 * @property string $event
 * @property string $severity
 * @property string $message
 * @property array|null $metadata
 * @property \Illuminate\Support\Carbon $created_at
 */
final class AppLog extends Model
{
    /** Immutable — no updated_at column. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'loggable_type',
        'loggable_id',
        'event',
        'severity',
        'message',
        'metadata',
    ];

    /**
     * Global flag to enable/disable all app logging.
     */
    protected static bool $enabled = true;

    public static function disable(): void
    {
        self::$enabled = false;
    }

    public static function enable(): void
    {
        self::$enabled = true;
    }

    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The model this log entry belongs to (Position, Account, Order, etc.).
     */
    public function loggable(): MorphTo
    {
        return $this->morphTo();
    }
}
