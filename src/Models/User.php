<?php

declare(strict_types=1);

namespace Kraite\Core\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use NotificationChannels\Pushover\PushoverChannel;
use NotificationChannels\Pushover\PushoverReceiver;
use RuntimeException;

/**
 * @property int $id
 * @property int|null $subscription_id
 * @property string $name
 * @property string $email
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property string|null $pushover_key
 * @property array<int, string> $notification_channels
 * @property array|null $behaviours
 * @property \Illuminate\Support\Carbon|null $last_logged_in_at
 * @property \Illuminate\Support\Carbon|null $previous_logged_in_at
 * @property bool $can_trade
 * @property bool $have_distinct_position_tokens_on_all_accounts
 * @property bool $is_active
 * @property bool $is_admin
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property string|null $_temp_delivery_group
 * @property bool $is_virtual
 */
final class User extends Authenticatable
{
    use HasFactory;
    use Notifiable;

    /**
     * Flag to indicate if this is a virtual user (non-persisted admin user).
     * Virtual users cannot be saved to the database.
     */
    public bool $is_virtual = false;

    protected $guarded = [];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_logged_in_at' => 'datetime',
        'previous_logged_in_at' => 'datetime',

        'can_trade' => 'boolean',
        'have_distinct_position_tokens_on_all_accounts' => 'boolean',
        'is_active' => 'boolean',
        'is_admin' => 'boolean',

        'password' => 'hashed',
        'pushover_key' => 'encrypted',
        'notification_channels' => 'array',
        'behaviours' => 'array',
    ];

    /**
     * @return MorphMany<Step, $this>
     */
    public function steps(): MorphMany
    {
        return $this->morphMany(Step::class, 'relatable');
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * @return HasMany<Account, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /**
     * @return HasManyThrough<Position, Account, $this>
     */
    public function positions(): HasManyThrough
    {
        return $this->hasManyThrough(Position::class, Account::class);
    }

    /**
     * @return MorphMany<ApiRequestLog, $this>
     */
    public function apiRequestLogs(): MorphMany
    {
        return $this->morphMany(ApiRequestLog::class, 'relatable');
    }

    /**
     * @return MorphMany<NotificationLog, $this>
     */
    public function notificationLogs(): MorphMany
    {
        return $this->morphMany(NotificationLog::class, 'relatable');
    }

    /**
     * Send a notification with optional delivery group routing.
     *
     * This method handles the temporary property pattern required because
     * the Pushover package doesn't pass the notification object to routing methods.
     *
     * @param  \Illuminate\Notifications\Notification  $notification
     * @param  string|null  $deliveryGroup  Delivery group name (exceptions, default, indicators) or null for individual user key
     */
    public function notifyWithGroup($notification, ?string $deliveryGroup = null): void
    {
        if ($deliveryGroup) {
            $this->_temp_delivery_group = $deliveryGroup;
        }

        $this->notify($notification);

        if ($deliveryGroup) {
            unset($this->_temp_delivery_group);
        }
    }

    /**
     * Send the password reset notification.
     *
     * @param  string  $token
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new \App\Notifications\ResetPassword($token));
    }

    /**
     * Route notifications for the Mail channel.
     *
     * Returns the user's email address for mail notifications.
     */
    public function routeNotificationForMail($notification): ?string
    {
        return $this->email;
    }

    /**
     * Route notifications for the Pushover channel.
     *
     * If notification has a deliveryGroup set, routes to that group.
     * Otherwise, routes to the user's individual pushover_key.
     */
    public function routeNotificationForPushover($notification): ?PushoverReceiver
    {
        // Get application token from Engine model
        $engine = Kraite::find(1);

        if (! $engine || ! $engine->admin_pushover_application_key) {
            return null;
        }

        $appToken = $engine->admin_pushover_application_key;

        // Determine delivery group from temp property (if set) or notification object
        // Note: Pushover package doesn't pass $notification, so it's often null
        $deliveryGroup = $this->_temp_delivery_group
            ?? ($notification && property_exists($notification, 'deliveryGroup') ? $notification->deliveryGroup : null);

        // If delivery group is specified, route to that group
        if ($deliveryGroup) {
            $groupConfig = config("kraite.api.pushover.delivery_groups.{$deliveryGroup}");

            if (! $groupConfig || ! isset($groupConfig['group_key'])) {
                return null;
            }

            return PushoverReceiver::withUserKey($groupConfig['group_key'])
                ->withApplicationToken($appToken);
        }

        // Otherwise, route to user's individual pushover_key
        // Check for temporary key first (used for testing without saving to database)
        $pushoverKey = $this->_temp_pushover_key ?? $this->pushover_key;

        if (! $pushoverKey) {
            return null;
        }

        return PushoverReceiver::withUserKey($pushoverKey)
            ->withApplicationToken($appToken);
    }

    /**
     * Get the notification channels with proper class mapping.
     *
     * @return array<int, string>
     */
    public function getNotificationChannelsAttribute($value): array
    {
        $channels = is_string($value) ? json_decode($value, associative: true) : $value;

        if (! is_array($channels) || empty($channels)) {
            return [];
        }

        return array_map(callback: static function ($channel) {
            return match ($channel) {
                'pushover' => PushoverChannel::class,
                'mail' => 'mail',
                default => $channel
            };
        }, array: $channels);
    }

    /**
     * Override save() to prevent virtual users from being persisted.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws RuntimeException
     */
    public function save(array $options = []): bool
    {
        if ($this->is_virtual) {
            throw new RuntimeException('Cannot save virtual admin user to database');
        }

        return parent::save($options);
    }

    protected static function newFactory()
    {
        return \Kraite\Core\Database\Factories\UserFactory::new();
    }
}
