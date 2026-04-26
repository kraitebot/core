<?php

declare(strict_types=1);

namespace Kraite\Core\Listeners;

use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Str;
use Kraite\Core\Enums\NotificationLogStatus;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\Notification;
use Kraite\Core\Models\NotificationLog;
use Kraite\Core\Models\User;
use ReflectionClass;
use ReflectionProperty;
use Throwable;

/**
 * NotificationLogListener
 *
 * Listens to Laravel notification events (NotificationSent, NotificationFailed)
 * and creates audit trail log entries in the notification_logs table.
 *
 * Automatically logs ALL notifications sent through the platform regardless of channel.
 */
final class NotificationLogListener
{
    /**
     * Handle the NotificationSent event.
     *
     * Logs successful notification delivery attempts with 'delivered' status.
     * Webhook events will update to 'opened' or bounce statuses later (mail channel only).
     */
    public function handleNotificationSent(NotificationSent $event): void
    {
        $this->createLog($event, NotificationLogStatus::Delivered->value);
    }

    /**
     * Handle the NotificationFailed event.
     *
     * Logs failed notification delivery attempts with error information.
     */
    public function handleNotificationFailed(NotificationFailed $event): void
    {
        $errorMessage = $event->data['exception'] ?? $event->data['error'] ?? 'Unknown error';
        if ($errorMessage instanceof Throwable) {
            $errorMessage = $errorMessage->getMessage();
        }

        /** @var string|null $errorMessage */
        $this->createLog($event, NotificationLogStatus::Failed->value, $errorMessage);
    }

    /**
     * Register the listeners for the subscriber.
     *
     * @return array<string, string>
     */
    public function subscribe(): array
    {
        return [
            NotificationSent::class => 'handleNotificationSent',
            NotificationFailed::class => 'handleNotificationFailed',
        ];
    }

    /**
     * Create a notification log entry from an event.
     *
     * @param  NotificationSent|NotificationFailed  $event
     */
    private function createLog($event, string $status, ?string $errorMessage = null): void
    {
        $notifiable = $event->notifiable;
        $notification = $event->notification;
        $channel = $event->channel;

        // Extract canonical from notification object
        $canonical = $this->extractCanonical($notification);

        // Extract user ID (null for admin virtual user)
        /** @var object $notifiable */
        $userId = $this->extractUserId($notifiable);

        // Determine relatable context model (NOT the user)
        [$relatableType, $relatableId] = $this->extractRelatable($notifiable);

        // Determine recipient based on channel
        $recipient = $this->extractRecipient($notifiable, $channel);

        // Extract response data (for sent events)
        $gatewayResponse = null;
        $httpHeadersReceived = null;
        $httpHeadersSent = null;
        $messageId = null;
        $rawEmailContent = null;
        if ($event instanceof NotificationSent && $event->response) {
            $gatewayResponse = $this->extractGatewayResponse($event->response);
            $httpHeadersReceived = $this->extractHttpHeaders($event->response);
            $httpHeadersSent = $this->extractHttpHeadersSent($event->response);
            $messageId = $this->extractMessageId($gatewayResponse, $channel);
            $rawEmailContent = $this->extractRawEmailContent($event->response, $channel);
        }

        // Build content dump for legal audit
        $contentDump = $this->buildContentDump($notification, $notifiable);

        // Lookup notification definition by canonical
        $notificationId = Notification::where('canonical', $canonical)->value('id');

        // Create log entry
        NotificationLog::create([
            'notification_id' => $notificationId,
            'canonical' => $canonical,
            'user_id' => $userId,
            'relatable_type' => $relatableType,
            'relatable_id' => $relatableId,
            'channel' => $this->normalizeChannel($channel),
            'recipient' => $recipient,
            'message_id' => $messageId,
            'sent_at' => now(),
            'status' => $status,
            'http_headers_sent' => $httpHeadersSent,
            'http_headers_received' => $httpHeadersReceived,
            'gateway_response' => $gatewayResponse,
            'content_dump' => $contentDump,
            'raw_email_content' => $rawEmailContent,
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * Extract canonical from notification object.
     */
    private function extractCanonical(object $notification): string
    {
        // Check if notification has canonical property with a non-empty value
        if (property_exists($notification, 'canonical') && is_string($notification->canonical) && $notification->canonical !== '') {
            return $notification->canonical;
        }

        // Check if notification has messageCanonical property (used in AlertNotification)
        if (property_exists($notification, 'messageCanonical') && is_string($notification->messageCanonical) && $notification->messageCanonical !== '') {
            return $notification->messageCanonical;
        }

        // Fallback: use descriptive uncategorized canonical instead of class name
        return 'uncategorized_notification';
    }

    /**
     * Extract user ID from notifiable (null for admin virtual user).
     */
    private function extractUserId(object $notifiable): ?int
    {
        // If notifiable is User, return user ID
        if ($notifiable instanceof User) {
            return $notifiable->id;
        }

        // If notifiable is Account, return the account's user_id
        if ($notifiable instanceof Account && $notifiable->user_id) {
            return $notifiable->user_id;
        }

        // Admin notifications have no user (virtual admin)
        return null;
    }

    /**
     * Extract relatable context model (Account, ApiSystem, ExchangeSymbol, etc.) - NOT the user.
     *
     * @return array{string|null, int|null}
     */
    private function extractRelatable(object $notifiable): array
    {
        // If notifiable is Account, use it as relatable
        if ($notifiable instanceof Account) {
            return [Account::class, $notifiable->id];
        }

        // If notifiable is User, DON'T use User as relatable - check for relatable property instead
        // (User is stored in user_id column, relatable is for context models)
        if ($notifiable instanceof User) {
            // Check if User has a relatable property (dynamically added by NotificationService)
            if (isset($notifiable->relatable) && is_object($notifiable->relatable)) {
                $relatable = $notifiable->relatable;
                if (method_exists($relatable, 'getMorphClass')) {
                    return [$relatable->getMorphClass(), $relatable->getKey()];
                }
                if (property_exists($relatable, 'id')) {
                    return [get_class($relatable), $relatable->id];
                }
            }

            // User notifications without additional context have no relatable
            return [null, null];
        }

        // Check if pseudo-notifiable has a relatable property (admin notifications with context)
        if (isset($notifiable->relatable) && is_object($notifiable->relatable)) {
            $relatable = $notifiable->relatable;
            if (method_exists($relatable, 'getMorphClass')) {
                return [$relatable->getMorphClass(), $relatable->getKey()];
            }
            if (property_exists($relatable, 'id')) {
                return [get_class($relatable), $relatable->id];
            }
        }

        // Admin notifications without additional context have no relatable model
        return [null, null];
    }

    /**
     * Extract recipient (email or Pushover key) based on channel.
     */
    private function extractRecipient(object $notifiable, string $channel): string
    {
        if (Str::contains($channel, 'mail') || $channel === 'mail') {
            // For mail, try to get email from notifiable
            if (method_exists($notifiable, 'routeNotificationFor')) {
                $email = $notifiable->routeNotificationFor('mail', null);
                if (is_string($email) && $email !== '') {
                    return $email;
                }
            }
            if (property_exists($notifiable, 'email') && is_string($notifiable->email)) {
                return $notifiable->email;
            }
        }

        if (Str::contains($channel, 'pushover') || Str::contains($channel, 'Pushover')) {
            // For Pushover, try to get key from notifiable
            if (method_exists($notifiable, 'routeNotificationFor')) {
                $receiver = $notifiable->routeNotificationFor('pushover', null);
                if (is_object($receiver)) {
                    // PushoverReceiver uses toArray()['user'] to get the key
                    if (method_exists($receiver, 'toArray')) {
                        $data = $receiver->toArray();
                        if (isset($data['user']) && is_string($data['user'])) {
                            return $data['user'];
                        }
                    }
                    // Fallback to getUserKey() method if it exists
                    if (method_exists($receiver, 'getUserKey')) {
                        $key = $receiver->getUserKey();
                        if (is_string($key)) {
                            return $key;
                        }
                    }
                }
            }
            if (property_exists($notifiable, 'pushover_key')) {
                $value = $notifiable->pushover_key ?? 'unknown';
                if (is_string($value)) {
                    return $value;
                }
            }
        }

        return 'unknown';
    }

    /**
     * Normalize channel name to simple string.
     */
    private function normalizeChannel(string $channel): string
    {
        if (Str::contains($channel, 'Pushover')) {
            return 'pushover';
        }
        if (Str::contains($channel, 'mail') || $channel === 'mail') {
            return 'mail';
        }

        return $channel;
    }

    /**
     * Extract gateway response from event response object.
     *
     * @return array<string, mixed>|null
     */
    private function extractGatewayResponse(mixed $response): ?array
    {
        // For Zeptomail: check if response has X-Zepto-Response header (added by ZeptoMailTransport)

        // Case 1: Laravel wrapped SentMessage (has getSymfonySentMessage method)
        if (is_object($response) && method_exists($response, 'getSymfonySentMessage')) {
            $symfonySentMessage = $response->getSymfonySentMessage();
            if (is_object($symfonySentMessage) && method_exists($symfonySentMessage, 'getOriginalMessage')) {
                $originalMessage = $symfonySentMessage->getOriginalMessage();
                if (is_object($originalMessage) && method_exists($originalMessage, 'getHeaders')) {
                    $headers = $originalMessage->getHeaders();
                    if (is_object($headers) && method_exists($headers, 'has') && $headers->has('X-Zepto-Response')) {
                        if (method_exists($headers, 'get')) {
                            $header = $headers->get('X-Zepto-Response');
                            if (is_object($header) && method_exists($header, 'getBodyAsString')) {
                                $headerValue = $header->getBodyAsString();
                                if (is_string($headerValue)) {
                                    $decoded = json_decode($headerValue, associative: true);
                                    if (is_array($decoded)) {
                                        /** @var array<string, mixed> */
                                        return $decoded;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        // Case 2: Direct Symfony SentMessage (from transport->send())
        if (is_object($response) && method_exists($response, 'getOriginalMessage')) {
            $originalMessage = $response->getOriginalMessage();
            if (is_object($originalMessage) && method_exists($originalMessage, 'getHeaders')) {
                $headers = $originalMessage->getHeaders();
                if (is_object($headers) && method_exists($headers, 'has') && $headers->has('X-Zepto-Response')) {
                    if (method_exists($headers, 'get')) {
                        $header = $headers->get('X-Zepto-Response');
                        if (is_object($header) && method_exists($header, 'getBodyAsString')) {
                            $headerValue = $header->getBodyAsString();
                            if (is_string($headerValue)) {
                                $decoded = json_decode($headerValue, associative: true);
                                if (is_array($decoded)) {
                                    /** @var array<string, mixed> */
                                    return $decoded;
                                }
                            }
                        }
                    }
                }
            }
        }

        if (is_array($response)) {
            /** @var array<string, mixed> */
            return $response;
        }

        if (is_object($response) && method_exists($response, 'toArray')) {
            $result = $response->toArray();
            if (is_array($result)) {
                /** @var array<string, mixed> */
                return $result;
            }
        }

        if (is_object($response)) {
            $encoded = json_encode($response);
            if (is_string($encoded)) {
                $decoded = json_decode($encoded, associative: true);
                if (is_array($decoded)) {
                    /** @var array<string, mixed> */
                    return $decoded;
                }
            }
        }

        return null;
    }

    /**
     * Extract HTTP headers from response object.
     *
     * @return array<string, mixed>|null
     */
    private function extractHttpHeaders(mixed $response): ?array
    {
        // For Pushover/HTTP responses, try to extract headers
        if (is_object($response) && method_exists($response, 'headers')) {
            $headers = $response->headers();
            if (is_array($headers)) {
                /** @var array<string, mixed> */
                return $headers;
            }
        }

        return null;
    }

    /**
     * Extract message ID from gateway response for tracking.
     *
     * For Zeptomail: uses request_id from API response (used in webhooks to identify emails)
     * For Pushover: uses receipt for emergency-priority notifications
     *
     * @param  array<string, mixed>|null  $gatewayResponse
     */
    private function extractMessageId(?array $gatewayResponse, string $channel): ?string
    {
        if (! $gatewayResponse) {
            return null;
        }

        $normalizedChannel = $this->normalizeChannel($channel);

        if ($normalizedChannel === 'mail') {
            // Zeptomail returns request_id in response (used in webhooks)
            if (isset($gatewayResponse['request_id']) && is_string($gatewayResponse['request_id'])) {
                return $gatewayResponse['request_id'];
            }
            // Fallback: check data array for message_id (other providers)
            if (isset($gatewayResponse['data']) && is_array($gatewayResponse['data'])) {
                if (isset($gatewayResponse['data']['message_id']) && is_string($gatewayResponse['data']['message_id'])) {
                    return $gatewayResponse['data']['message_id'];
                }
                if (isset($gatewayResponse['data'][0]) && is_array($gatewayResponse['data'][0])) {
                    if (isset($gatewayResponse['data'][0]['message_id']) && is_string($gatewayResponse['data'][0]['message_id'])) {
                        return $gatewayResponse['data'][0]['message_id'];
                    }
                }
            }
        }

        if ($normalizedChannel === 'pushover') {
            // Pushover returns receipt for emergency-priority notifications
            if (isset($gatewayResponse['receipt']) && is_string($gatewayResponse['receipt'])) {
                return $gatewayResponse['receipt'];
            }
        }

        return null;
    }

    /**
     * Build content dump for legal audit.
     *
     * Captures the full notification content including title, message, and parameters.
     */
    private function buildContentDump(object $notification, object $notifiable): string
    {
        $dump = [];

        // Extract all public properties
        $reflection = new ReflectionClass($notification);
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $propertyName = $property->getName();
            $dump[$propertyName] = $notification->{$propertyName} ?? null;
        }

        // Also try to extract via methods
        if (method_exists($notification, 'toArray')) {
            $dump['toArray'] = $notification->toArray($notifiable);
        }

        $encoded = json_encode($dump, flags: JSON_PRETTY_PRINT);

        return is_string($encoded) ? $encoded : '{}';
    }

    /**
     * Extract HTTP headers sent to the gateway.
     *
     * @return array<string, mixed>|null
     */
    private function extractHttpHeadersSent(mixed $response): ?array
    {
        // For Zeptomail: check if response has X-Zepto-Request-Headers header (added by ZeptoMailTransport)

        // Case 1: Laravel wrapped SentMessage
        if (is_object($response) && method_exists($response, 'getSymfonySentMessage')) {
            $symfonySentMessage = $response->getSymfonySentMessage();
            if (is_object($symfonySentMessage) && method_exists($symfonySentMessage, 'getOriginalMessage')) {
                $originalMessage = $symfonySentMessage->getOriginalMessage();
                if (is_object($originalMessage) && method_exists($originalMessage, 'getHeaders')) {
                    $headers = $originalMessage->getHeaders();
                    if (is_object($headers) && method_exists($headers, 'has') && $headers->has('X-Zepto-Request-Headers')) {
                        if (method_exists($headers, 'get')) {
                            $header = $headers->get('X-Zepto-Request-Headers');
                            if (is_object($header) && method_exists($header, 'getBodyAsString')) {
                                $headerValue = $header->getBodyAsString();
                                if (is_string($headerValue)) {
                                    $decoded = json_decode($headerValue, associative: true);
                                    if (is_array($decoded)) {
                                        /** @var array<string, mixed> */
                                        return $decoded;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        // Case 2: Direct Symfony SentMessage
        if (is_object($response) && method_exists($response, 'getOriginalMessage')) {
            $originalMessage = $response->getOriginalMessage();
            if (is_object($originalMessage) && method_exists($originalMessage, 'getHeaders')) {
                $headers = $originalMessage->getHeaders();
                if (is_object($headers) && method_exists($headers, 'has') && $headers->has('X-Zepto-Request-Headers')) {
                    if (method_exists($headers, 'get')) {
                        $header = $headers->get('X-Zepto-Request-Headers');
                        if (is_object($header) && method_exists($header, 'getBodyAsString')) {
                            $headerValue = $header->getBodyAsString();
                            if (is_string($headerValue)) {
                                $decoded = json_decode($headerValue, associative: true);
                                if (is_array($decoded)) {
                                    /** @var array<string, mixed> */
                                    return $decoded;
                                }
                            }
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Extract raw email content (HTML/text) for mail viewers.
     */
    private function extractRawEmailContent(mixed $response, string $channel): ?string
    {
        $normalizedChannel = $this->normalizeChannel($channel);

        // Only extract for mail channel
        if ($normalizedChannel !== 'mail') {
            return null;
        }

        // Case 1: Laravel wrapped SentMessage
        if (is_object($response) && method_exists($response, 'getSymfonySentMessage')) {
            $symfonySentMessage = $response->getSymfonySentMessage();
            if (is_object($symfonySentMessage) && method_exists($symfonySentMessage, 'getOriginalMessage')) {
                $originalMessage = $symfonySentMessage->getOriginalMessage();

                // Get HTML body (priority) or text body (fallback)
                if (is_object($originalMessage) && method_exists($originalMessage, 'getHtmlBody')) {
                    $htmlBody = $originalMessage->getHtmlBody();
                    if (is_string($htmlBody) && $htmlBody !== '') {
                        return $htmlBody;
                    }
                }

                if (is_object($originalMessage) && method_exists($originalMessage, 'getTextBody')) {
                    $textBody = $originalMessage->getTextBody();
                    if (is_string($textBody) && $textBody !== '') {
                        return $textBody;
                    }
                }
            }
        }

        // Case 2: Direct Symfony SentMessage
        if (is_object($response) && method_exists($response, 'getOriginalMessage')) {
            $originalMessage = $response->getOriginalMessage();

            // Get HTML body (priority) or text body (fallback)
            if (is_object($originalMessage) && method_exists($originalMessage, 'getHtmlBody')) {
                $htmlBody = $originalMessage->getHtmlBody();
                if (is_string($htmlBody) && $htmlBody !== '') {
                    return $htmlBody;
                }
            }

            if (is_object($originalMessage) && method_exists($originalMessage, 'getTextBody')) {
                $textBody = $originalMessage->getTextBody();
                if (is_string($textBody) && $textBody !== '') {
                    return $textBody;
                }
            }
        }

        return null;
    }
}
