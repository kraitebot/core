<?php

declare(strict_types=1);

namespace Kraite\Core\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Kraite\Core\Concerns\Notification\HasGetters;
use Kraite\Core\Concerns\Notification\HasScopes;
use Kraite\Core\Database\Factories\NotificationFactory;
use Kraite\Core\Enums\NotificationSeverity;

/**
 * Notification
 *
 * Registry of notification message templates available in the system.
 * These are base canonicals (e.g., 'ip_not_whitelisted') used by NotificationMessageBuilder.
 *
 * Completely separate from throttle_rules - this controls WHAT to say, not HOW OFTEN to say it.
 *
 * @property int $id
 * @property string $canonical
 * @property string $title
 * @property string|null $description
 * @property string|null $detailed_description
 * @property string|null $usage_reference
 * @property bool $verified
 * @property NotificationSeverity|null $default_severity
 * @property array<int, string>|null $cache_key
 * @property bool $is_active
 * @property bool $has_threshold
 * @property int|null $threshold_max_notifications
 * @property int|null $threshold_max_duration_minutes
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, NotificationLog> $logs
 */
final class Notification extends Model
{
    use HasFactory;
    use HasGetters;
    use HasScopes;

    protected $guarded = [];

    protected $casts = [
        'default_severity' => NotificationSeverity::class,
        'verified' => 'boolean',
        'cache_key' => 'array',
        'is_active' => 'boolean',
        'has_threshold' => 'boolean',
        'threshold_max_notifications' => 'integer',
        'threshold_max_duration_minutes' => 'integer',
    ];

    /**
     * Get all notification logs that used this notification definition.
     *
     * @return HasMany<NotificationLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(NotificationLog::class);
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): NotificationFactory
    {
        return NotificationFactory::new();
    }
}
