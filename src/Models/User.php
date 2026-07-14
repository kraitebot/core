<?php

declare(strict_types=1);

namespace Kraite\Core\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Kraite\Core\Database\Factories\UserFactory;
use Kraite\Core\Support\Billing\BillingManager;
use Kraite\Core\Support\Math;
use Kraite\Core\Support\NotificationService;
use LogicException;
use NotificationChannels\Pushover\PushoverChannel;
use NotificationChannels\Pushover\PushoverReceiver;
use NotificationChannels\Telegram\TelegramChannel;
use RuntimeException;
use SensitiveParameter;

/**
 * @property int $id
 * @property string $uuid
 * @property int|null $subscription_id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property string|null $pushover_key
 * @property string|null $telegram_chat_id
 * @property array<int, string> $notification_channels
 * @property bool|null $notifications_enabled
 * @property array|null $behaviours
 * @property Carbon|null $last_logged_in_at
 * @property Carbon|null $previous_logged_in_at
 * @property bool $can_trade
 * @property bool $have_distinct_position_tokens_on_all_accounts
 * @property bool $is_active
 * @property bool $is_admin
 * @property string $wallet_balance_usdt
 * @property Carbon|null $trial_started_at
 * @property int|null $trial_days_override
 * @property Carbon|null $subscription_renews_at
 * @property Carbon|null $subscription_paused_at
 * @property int|null $active_account_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property string|null $_temp_delivery_group
 * @property bool $is_virtual
 * @property-read Subscription|null $subscription
 * @property-read Account|null $activeAccount
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
        'trial_started_at' => 'datetime',
        'subscription_renews_at' => 'datetime',
        'subscription_paused_at' => 'datetime',

        'can_trade' => 'boolean',
        'have_distinct_position_tokens_on_all_accounts' => 'boolean',
        'is_active' => 'boolean',
        'is_admin' => 'boolean',

        'wallet_balance_usdt' => 'decimal:4',

        'password' => 'hashed',
        'pushover_key' => 'encrypted',
        'notification_channels' => 'array',
        'notifications_enabled' => 'boolean',
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
     * @return BelongsTo<Account, $this>
     */
    public function activeAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'active_account_id');
    }

    /**
     * @return HasMany<WalletTransaction, $this>
     */
    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    /**
     * @return HasMany<Account, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /**
     * Entry point Bruno asked for: `$user->billing()->subscription()->isActive()`.
     * Returns a `BillingManager` scoped to this user. Phase 2 will
     * grow `wallet()` and `coupons()` accessors off the same facade.
     */
    public function billing(): BillingManager
    {
        return new BillingManager($this);
    }

    /**
     * Effective trial duration in days. Per-user override wins; falls
     * back to the user's subscription tier default; zero if neither is
     * set.
     */
    public function effectiveTrialDays(): int
    {
        if ($this->trial_days_override !== null) {
            return (int) $this->trial_days_override;
        }

        return (int) ($this->subscription?->trial_days ?? 0);
    }

    /**
     * Start the user's one-time trial and establish the first renewal
     * anchor. Keeping both timestamps together prevents a completed trial
     * from becoming permanently unrenewable.
     */
    public function startTrial(?CarbonInterface $startedAt = null): void
    {
        if ($this->trial_started_at !== null) {
            return;
        }

        if ($this->subscription_id === null) {
            throw new LogicException("User {$this->id} cannot start a trial without a subscription tier.");
        }

        $this->trial_started_at = ($startedAt ?? now())->copy();
        $this->syncTrialRenewalAnchor();
    }

    /**
     * Recalculate the first renewal from the active trial and current tier.
     * This is used when a trial user changes plan or receives an override.
     */
    public function syncTrialRenewalAnchor(): void
    {
        if ($this->trial_started_at === null) {
            return;
        }

        if ($this->subscription_id === null) {
            throw new LogicException("User {$this->id} cannot establish a renewal without a subscription tier.");
        }

        $this->unsetRelation('subscription');
        $this->load('subscription');
        $this->subscription_renews_at = $this->trial_started_at
            ->copy()
            ->addDays(max(0, $this->effectiveTrialDays()));
        $this->save();
    }

    /**
     * Trial is active when trial_started_at is set and the configured
     * trial duration hasn't elapsed yet.
     */
    public function isTrialActive(): bool
    {
        if ($this->trial_started_at === null) {
            return false;
        }

        $trialDays = $this->effectiveTrialDays();

        if ($trialDays <= 0) {
            return false;
        }

        return $this->trial_started_at->copy()->addDays($trialDays)->isFuture();
    }

    /**
     * Trial was started but has elapsed. Distinct from "trial never
     * started" so callers (e.g. trial-ending notifier) can identify
     * users who consumed their trial vs. those who never engaged.
     */
    public function isTrialExpired(): bool
    {
        if ($this->trial_started_at === null) {
            return false;
        }

        return ! $this->isTrialActive();
    }

    /**
     * True when the user has voluntarily paused their subscription.
     */
    public function isPaused(): bool
    {
        return $this->subscription_paused_at !== null;
    }

    /**
     * True when the wallet covers the current monthly tier rate. Used
     * by the billing UI to render the green/red coverage badge.
     */
    public function subscriptionCoversNextRenewal(): bool
    {
        $rate = (string) ($this->subscription?->monthly_rate_usdt ?? '0');

        if (Math::lte($rate, '0')) {
            return true;
        }

        return Math::gte((string) $this->wallet_balance_usdt, $rate);
    }

    /**
     * USDT short of the next monthly renewal. Returns 0 when the
     * wallet already covers the rate.
     */
    public function renewalShortfallUsdt(): float
    {
        $rate = (string) ($this->subscription?->monthly_rate_usdt ?? '0');

        if (Math::lte($rate, '0')) {
            return 0.0;
        }

        $diff = Math::sub($rate, (string) $this->wallet_balance_usdt);

        return Math::lt($diff, '0') ? 0.0 : (float) $diff;
    }

    /**
     * Read-only mode gate. The trading guards consult this before
     * opening new positions; existing positions are never affected.
     *
     *  - Paused → always closing (pause overrides everything).
     *  - Trial active → not closing.
     *  - No renewal anchor set (post-trial user with no cycle) →
     *    closing until first renewal lands.
     *  - Renewal anchor in the past → closing (cron has not yet
     *    successfully renewed, or wallet is short).
     */
    public function isInClosingMode(): bool
    {
        if ($this->isPaused()) {
            return true;
        }

        if ($this->isTrialActive()) {
            return false;
        }

        if (
            $this->subscription !== null
            && Math::lte((string) $this->subscription->monthly_rate_usdt, '0')
        ) {
            return false;
        }

        if ($this->subscription_renews_at === null) {
            return true;
        }

        return $this->subscription_renews_at->isPast();
    }

    /**
     * Pause the subscription. All accounts go read-only until resume.
     * No-op if already paused.
     */
    public function pause(): void
    {
        if ($this->isPaused()) {
            return;
        }

        $this->subscription_paused_at = now();
        $this->save();
    }

    /**
     * Resume from pause. Pushes the renewal anchor forward by the
     * pause duration so the user gets back the days they paid for.
     * No-op if not paused.
     */
    public function resume(): void
    {
        if (! $this->isPaused()) {
            return;
        }

        $pauseSeconds = (int) $this->subscription_paused_at->diffInSeconds(now());

        if ($this->subscription_renews_at !== null && $pauseSeconds > 0) {
            $this->subscription_renews_at = $this->subscription_renews_at
                ->copy()
                ->addSeconds($pauseSeconds);
        }

        $this->subscription_paused_at = null;
        $this->save();
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
     * @param  Notification  $notification
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
    public function sendPasswordResetNotification(#[SensitiveParameter] $token): void
    {
        $resetUrl = mb_rtrim((string) config('kraite.admin_url'), '/').'/reset-password/'.$token.'?email='.urlencode($this->getEmailForPasswordReset());
        $expireMinutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);

        NotificationService::send(
            user: $this,
            canonical: 'password_reset',
            referenceData: [
                'reset_url' => $resetUrl,
                'expire_minutes' => $expireMinutes,
            ],
            channels: ['mail'],
        );
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
     * Route notifications for the Telegram channel.
     *
     * Returns the user's `telegram_chat_id` (captured once after they
     * DM the bot configured via `TELEGRAM_BOT_TOKEN`). When null,
     * Laravel's notification dispatcher silently skips the channel —
     * no exception, no log noise.
     */
    public function routeNotificationForTelegram($notification): ?string
    {
        return $this->telegram_chat_id;
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
                'telegram' => TelegramChannel::class,
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

    /**
     * Auto-stamp `uuid` at create time so callers never have to remember it.
     * The column is the public-facing identifier used in URLs that need to
     * leak nothing about row count or creation order — e.g. the
     * `admin.kraite.com/register/{uuid}` registration-completion page that
     * a private-beta confirmer lands on after clicking their verify link.
     */
    protected static function booted(): void
    {
        self::creating(function (self $user): void {
            if (empty($user->uuid)) {
                $user->uuid = (string) Str::uuid();
            }
        });
    }

    protected static function newFactory()
    {
        return UserFactory::new();
    }
}
