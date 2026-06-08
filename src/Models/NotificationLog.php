<?php

declare(strict_types=1);

namespace Kraite\Core\Models;

use Database\Factories\NotificationLogFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Kraite\Core\Enums\NotificationLogStatus;

/**
 * NotificationLog
 *
 * Legal audit trail for ALL notifications sent through the platform.
 * Logs notification delivery attempts, confirmations, and failures across all channels.
 *
 * One log entry per channel per notification sent.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $notification_id
 * @property string $canonical
 * @property int|null $user_id
 * @property string|null $relatable_type
 * @property int|null $relatable_id
 * @property string $channel
 * @property string $recipient
 * @property string|null $message_id
 * @property Carbon $sent_at
 * @property Carbon|null $opened_at
 * @property Carbon|null $soft_bounced_at
 * @property Carbon|null $hard_bounced_at
 * @property string $status
 * @property bool $passed_threshold
 * @property array<string, mixed>|null $http_headers_sent
 * @property array<string, mixed>|null $http_headers_received
 * @property array<string, mixed>|null $gateway_response
 * @property string|null $content_dump
 * @property string|null $raw_email_content
 * @property string|null $error_message
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Notification|null $notification
 * @property-read User|null $user
 * @property-read Model|null $relatable
 */
final class NotificationLog extends Model
{
    /** @use HasFactory<NotificationLogFactory> */
    use HasFactory;

    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'sent_at' => 'datetime',
        'opened_at' => 'datetime',
        'soft_bounced_at' => 'datetime',
        'hard_bounced_at' => 'datetime',
        'http_headers_sent' => 'array',
        'http_headers_received' => 'array',
        'gateway_response' => 'array',
        'passed_threshold' => 'boolean',
    ];

    /**
     * Get the columns that should receive a unique identifier.
     *
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * The notification definition used for this log entry.
     *
     * @return BelongsTo<Notification, $this>
     */
    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }

    /**
     * The user who received this notification (null for admin virtual user).
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The relatable context model (Account, ApiSystem, ExchangeSymbol, etc.) - NOT the user.
     *
     * @return MorphTo<Model, $this>
     */
    public function relatable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Scope query to specific canonical.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeByCanonical(Builder $query, string $canonical): Builder
    {
        return $query->where('canonical', $canonical);
    }

    /**
     * Scope query to specific channel.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeByChannel(Builder $query, string $channel): Builder
    {
        return $query->where('channel', $channel);
    }

    /**
     * Scope query to specific status.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope query to failed notifications.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', NotificationLogStatus::Failed->value);
    }

    /**
     * Scope query to delivered notifications.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeDelivered(Builder $query): Builder
    {
        return $query->where('status', NotificationLogStatus::Delivered->value);
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): Factory
    {
        return NotificationLogFactory::new();
    }
}
