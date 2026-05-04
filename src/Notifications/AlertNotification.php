<?php

declare(strict_types=1);

namespace Kraite\Core\Notifications;


use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Notification;
use Kraite\Core\Mail\AlertMail;
use NotificationChannels\Pushover\PushoverChannel;
use NotificationChannels\Pushover\PushoverMessage;
use NotificationChannels\Telegram\TelegramMessage;

/**
 * AlertNotification
 *
 * Unified notification class for alerts.
 * Supports multiple channels: Pushover, Email (more to come: SMS)
 * Respects user's notification_channels preference.
 * Can be sent to individual users or admin delivery groups.
 */
final class AlertNotification extends Notification
{
    // Removed Queueable - notifications sent immediately so routing has access to notification object

    /**
     * Create a new notification instance.
     *
     * @param  string  $message  The notification message body
     * @param  string  $title  The notification title
     * @param  string|null  $canonical  Notification canonical identifier (e.g., 'ip_not_whitelisted', 'server_rate_limit_exceeded')
     * @param  string|null  $deliveryGroup  Delivery group name (exceptions, default, indicators) or null for individual user
     * @param  array  $additionalParameters  Extra parameters (sound, priority, url, etc.)
     * @param  \Kraite\Core\Enums\NotificationSeverity|null  $severity  Severity level for visual styling
     * @param  string|null  $pushoverMessage  Override message for Pushover (defaults to $message)
     * @param  string|null  $exchange  Exchange name for email subject (e.g., 'binance', 'bybit')
     * @param  string|null  $serverIp  Server IP address for email subject (e.g., '192.168.1.100')
     * @param  string|null  $telegramMessage  Override message for Telegram (defaults to $pushoverMessage if present, else $message — keeps the chat-friendly compact format aligned across the two terse channels)
     */
    public function __construct(
        public string $message,
        public string $title = 'Alert',
        public ?string $canonical = null,
        public ?string $deliveryGroup = null,
        public array $additionalParameters = [],
        public ?\Kraite\Core\Enums\NotificationSeverity $severity = null,
        public ?string $pushoverMessage = null,
        public ?string $exchange = null,
        public ?string $serverIp = null,
        public ?string $telegramMessage = null,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * Respects each user's individual notification_channels preference.
     * If a user has no channels configured, defaults to Pushover.
     * Only sends to active users (is_active = true).
     *
     * @param  mixed  $notifiable
     * @return array<int, string>
     */
    public function via($notifiable): array
    {
        // Don't send notifications to inactive users
        if (! $notifiable->is_active) {
            return [];
        }

        return $notifiable->notification_channels ?? [PushoverChannel::class];
    }

    /**
     * Get the Pushover representation of the notification.
     *
     * The application token and routing (group key vs user key) is determined by
     * User::routeNotificationForPushover() based on this notification's deliveryGroup property.
     *
     * Priority is determined by severity:
     * - Critical = Emergency priority (2)
     * - All others = Normal priority (0)
     *
     * @param  mixed  $notifiable
     */
    public function toPushover($notifiable): PushoverMessage
    {
        // Use pushoverMessage if provided, otherwise use the main message
        $pushoverText = $this->pushoverMessage ?? $this->message;

        // Build title with optional hostname prefix
        $title = $this->title;
        if (config('kraite.prefix_hostname_on_notifications', false)) {
            $hostname = gethostname();
            $title = "[{$hostname}] {$title}";
        }

        $message = PushoverMessage::create($pushoverText)
            ->title($title);

        // Determine priority based on severity
        // Critical = emergency priority (2), all others = normal priority (0)
        $priority = ($this->severity === \Kraite\Core\Enums\NotificationSeverity::Critical) ? 2 : 0;

        // Allow manual override via additionalParameters if explicitly provided
        if (isset($this->additionalParameters['priority'])) {
            $priority = $this->additionalParameters['priority'];
        }

        // Apply priority
        match ($priority) {
            -2 => $message->lowestPriority(),
            -1 => $message->lowPriority(),
            0 => $message->normalPriority(),
            1 => $message->highPriority(),
            2 => $message->emergencyPriority(
                $this->additionalParameters['retry'] ?? 30,
                $this->additionalParameters['expire'] ?? 3600
            ),
            default => $message->normalPriority()
        };

        // For emergency priority (2), use siren sound unless overridden
        if ($priority === 2) {
            $message->sound($this->additionalParameters['sound'] ?? 'siren');
        } elseif (isset($this->additionalParameters['sound'])) {
            // Apply custom sound if provided for non-emergency
            $message->sound($this->additionalParameters['sound']);
        }

        // Apply URL if provided
        if (isset($this->additionalParameters['url'])) {
            $message->url(
                $this->additionalParameters['url'],
                $this->additionalParameters['url_title'] ?? 'View Details'
            );
        }

        return $message;
    }

    /**
     * Get the Telegram representation of the notification.
     *
     * Routes to `$notifiable->routeNotificationForTelegram()` (the
     * user's `telegram_chat_id`). Falls back to pushover message
     * text if `telegramMessage` wasn't explicitly set — both
     * channels render in compact form, so the same body usually
     * fits.
     *
     * @param  mixed  $notifiable
     */
    public function toTelegram($notifiable): TelegramMessage
    {
        $body = $this->telegramMessage ?? $this->pushoverMessage ?? $this->message;

        $title = $this->title;
        if (config('kraite.prefix_hostname_on_notifications', false)) {
            $hostname = gethostname();
            $title = "[{$hostname}] {$title}";
        }

        // Telegram MarkdownV2 reserves a tight set of special chars
        // that must be backslash-escaped EVERYWHERE they appear.
        // Easier: use HTML parse mode — only `<`, `>`, `&` need
        // escaping in the body. Title in <b>...</b>.
        $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $safeBody = htmlspecialchars($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return TelegramMessage::create()
            ->to((string) $notifiable->telegram_chat_id)
            ->content("<b>{$safeTitle}</b>\n\n{$safeBody}")
            ->options(['parse_mode' => 'HTML']);
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     */
    public function toMail($notifiable): Mailable
    {
        return (new AlertMail(
            notificationTitle: $this->title,
            notificationMessage: $this->message,
            severity: $this->severity,
            actionUrl: $this->additionalParameters['url'] ?? null,
            actionLabel: $this->additionalParameters['url_title'] ?? null,
            details: $this->additionalParameters['details'] ?? null,
            hostname: gethostname(),
            userName: $notifiable->name ?? null,
            exchange: $this->exchange,
            serverIp: $this->serverIp
        ))->to($notifiable->email);
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array<string, mixed>
     */
    public function toArray($notifiable): array
    {
        return [
            'message' => $this->message,
            'title' => $this->title,
            'delivery_group' => $this->deliveryGroup,
        ];
    }

    /**
     * Get the configured priority for this notification's delivery group.
     *
     * Retrieves priority setting from delivery group configuration.
     * Returns null if no priority is configured (will use additionalParameters priority).
     *
     * @return int|null Priority: -2 (lowest), -1 (low), 0 (normal), 1 (high), 2 (emergency)
     */
    public function getDeliveryGroupPriority(): ?int
    {
        // If no delivery group, use additionalParameters priority
        if (! $this->deliveryGroup) {
            return null;
        }

        // Get the delivery group configuration
        $groupConfig = config("kraite.api.pushover.delivery_groups.{$this->deliveryGroup}");

        // Return the configured priority, or null if not set
        return $groupConfig['priority'] ?? null;
    }
}
