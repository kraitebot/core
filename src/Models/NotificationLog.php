<?php

declare(strict_types=1);

namespace Kraite\Core\Models;

use Database\Factories\NotificationLogFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

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
 * @property \Illuminate\Support\Carbon $sent_at
 * @property \Illuminate\Support\Carbon|null $opened_at
 * @property \Illuminate\Support\Carbon|null $soft_bounced_at
 * @property \Illuminate\Support\Carbon|null $hard_bounced_at
 * @property string $status
 * @property array<string, mixed>|null $http_headers_sent
 * @property array<string, mixed>|null $http_headers_received
 * @property array<string, mixed>|null $gateway_response
 * @property string|null $content_dump
 * @property string|null $raw_email_content
 * @property string|null $error_message
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read Notification|null $notification
 * @property-read User|null $user
 * @property-read Model|null $relatable
 */
final class NotificationLog extends Model
{
    /** @use HasFactory<\Database\Factories\NotificationLogFactory> */
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
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeByCanonical(\Illuminate\Database\Eloquent\Builder $query, string $canonical): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('canonical', $canonical);
    }

    /**
     * Scope query to specific channel.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeByChannel(\Illuminate\Database\Eloquent\Builder $query, string $channel): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('channel', $channel);
    }

    /**
     * Scope query to specific status.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeByStatus(\Illuminate\Database\Eloquent\Builder $query, string $status): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope query to failed notifications.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeFailed(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('status', 'failed');
    }

    /**
     * Scope query to delivered notifications.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeDelivered(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('status', 'delivered');
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): Factory
    {
        return NotificationLogFactory::new();
    }
}
