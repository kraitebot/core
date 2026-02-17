<?php

declare(strict_types=1);

namespace Kraite\Core\Http\Controllers\Webhooks;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Log;
use Kraite\Core\Models\NotificationLog;
use Throwable;

/**
 * NotificationWebhookController
 *
 * Handles delivery confirmation webhooks from notification gateways:
 * - Zeptomail: Hard bounce, soft bounce, open events
 * - Pushover: Receipt acknowledgment callbacks
 *
 * Updates notification_logs table with delivery confirmation timestamps and status.
 */
final class NotificationWebhookController extends Controller
{
    /**
     * Handle Zeptomail webhook events.
     *
     * Processes: hard bounce, soft bounce, open events.
     *
     * @see https://www.zoho.com/zeptomail/help/webhooks.html
     */
    public function zeptomail(Request $request): JsonResponse
    {
        // Handle GET request for Zeptomail's webhook verification test
        if ($request->isMethod('get')) {
            return response()->json([
                'status' => 'ready',
                'webhook' => 'zeptomail',
                'message' => 'Webhook endpoint is ready to receive POST requests',
            ]);
        }

        try {
            // Verify webhook signature for security
            if (! $this->verifyZeptomailSignature($request)) {
                Log::warning('[ZEPTOMAIL WEBHOOK] Invalid signature', [
                    'ip' => $request->ip(),
                ]);

                return response()->json(['status' => 'error', 'message' => 'Invalid signature'], 401);
            }

            // Zeptomail sends events as JSON
            $payload = $request->json()->all();

            // Extract event type and event data (both are arrays)
            $eventNames = $payload['event_name'] ?? null;
            $eventMessages = $payload['event_message'] ?? null;

            if (! is_array($eventNames) || ! is_array($eventMessages) || count($eventNames) === 0 || count($eventMessages) === 0) {
                Log::warning('[ZEPTOMAIL WEBHOOK] Missing event_name or event_message');

                // Zeptomail requires status 200 even for errors
                return response()->json(['status' => 'error', 'message' => 'Missing event_name or event_message'], 200);
            }

            // Get first event (Zeptomail sends arrays but typically one event per webhook)
            $eventType = $eventNames[0];
            $eventData = $eventMessages[0];

            if (! is_string($eventType) || ! is_array($eventData)) {
                Log::warning('[ZEPTOMAIL WEBHOOK] Invalid event format');

                return response()->json(['status' => 'error', 'message' => 'Invalid event format'], 200);
            }

            // Process based on event type
            /** @var array<string, mixed> $eventData */
            match ($eventType) {
                'hardbounce' => $this->handleZeptomailBounce($request, $eventData, 'hard_bounce'),
                'softbounce' => $this->handleZeptomailBounce($request, $eventData, 'soft_bounce'),
                'email_open' => $this->handleZeptomailOpen($request, $eventData),
                'email_link_click' => $this->handleZeptomailClick($request, $eventData),
                default => Log::info('[ZEPTOMAIL WEBHOOK] Unhandled event type', ['event_type' => $eventType]),
            };

            return response()->json(['status' => 'success'], 200);
        } catch (Throwable $e) {
            Log::error('[ZEPTOMAIL WEBHOOK] Exception', [
                'message' => $e->getMessage(),
            ]);

            // Zeptomail requires status 200 even for errors
            return response()->json(['status' => 'error', 'message' => 'Internal error'], 200);
        }
    }

    /**
     * Handle Pushover receipt acknowledgment callback.
     *
     * Called when user acknowledges an emergency-priority notification.
     *
     * @see https://pushover.net/api#receipt
     */
    public function pushover(Request $request): JsonResponse
    {
        try {
            // Pushover sends callbacks as form data
            $receipt = $request->input('receipt');
            $acknowledged = $request->input('acknowledged');
            $acknowledgedAt = $request->input('acknowledged_at');

            // Validate receipt format (Pushover receipts are 30-char alphanumeric strings)
            if (! $receipt || ! is_string($receipt) || ! preg_match('/^[a-zA-Z0-9]{20,50}$/', $receipt)) {
                return response()->json(['success' => false, 'message' => 'Invalid receipt'], 200);
            }

            // Find notification log by receipt (stored in gateway_response)
            $notificationLog = NotificationLog::where('channel', 'pushover')
                ->where('gateway_response->receipt', $receipt)
                ->first();

            if (! $notificationLog) {
                return response()->json(['success' => false, 'message' => 'Notification not found'], 200);
            }

            // Update with acknowledgment timestamp
            if ($acknowledged && $acknowledgedAt !== null && is_numeric($acknowledgedAt)) {
                $notificationLog->update([
                    'status' => 'delivered',
                ]);
            }

            return response()->json(['status' => 'success'], 200);
        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'message' => 'Internal error'], 200);
        }
    }

    /**
     * Handle Zeptomail bounce events (hard or soft).
     *
     * @param  array<string, mixed>  $data
     */
    private function handleZeptomailBounce(Request $request, array $data, string $bounceType): void
    {
        // Extract request_id from webhook payload (at root level of event_message object)
        $requestId = is_string($data['request_id'] ?? null) ? $data['request_id'] : null;

        // Extract bounce details from event_data array
        $eventDataArray = is_array($data['event_data'] ?? null) ? $data['event_data'] : [];
        $eventData = is_array($eventDataArray[0] ?? null) ? $eventDataArray[0] : [];
        $detailsArray = is_array($eventData['details'] ?? null) ? $eventData['details'] : [];
        $details = is_array($detailsArray[0] ?? null) ? $detailsArray[0] : [];

        $bounceReason = is_string($details['reason'] ?? null) ? $details['reason'] : 'Unknown';
        $diagnosticMessage = is_string($details['diagnostic_message'] ?? null) ? $details['diagnostic_message'] : null;
        $bouncedRecipient = is_string($details['bounced_recipient'] ?? null) ? $details['bounced_recipient'] : null;
        $bounceTime = is_string($details['time'] ?? null) ? $details['time'] : null;

        // Build comprehensive error message
        $errorMessage = $bounceReason;
        if ($diagnosticMessage !== null) {
            $errorMessage .= ' - '.$diagnosticMessage;
        }
        if ($bouncedRecipient !== null) {
            $errorMessage .= ' (Recipient: '.$bouncedRecipient.')';
        }

        // Find notification log by message_id (which stores the request_id from API response)
        $notificationLog = $this->findNotificationLogByRequestIdOrEmail($data, $requestId);

        if (! $notificationLog) {
            Log::warning('[ZEPTOMAIL WEBHOOK] No notification log found for bounce', [
                'request_id' => $requestId,
                'recipient' => $bouncedRecipient,
            ]);

            return;
        }

        // Update status and bounce timestamp based on bounce type
        $status = $bounceType === 'hard_bounce' ? 'hard bounced' : 'soft bounced';
        $bounceTimestamp = $bounceTime ? \Carbon\Carbon::parse($bounceTime) : now();
        $bounceField = $bounceType === 'hard_bounce' ? 'hard_bounced_at' : 'soft_bounced_at';

        $notificationLog->update([
            'status' => $status,
            $bounceField => $bounceTimestamp,
            'error_message' => $errorMessage,
            'http_headers_received' => $request->headers->all(),
            'gateway_response' => array_merge($notificationLog->gateway_response ?? [], ['bounce_event' => $data]),
        ]);

        Log::info('[ZEPTOMAIL WEBHOOK] Bounce processed', [
            'notification_log_id' => $notificationLog->id,
            'bounce_type' => $bounceType,
        ]);
    }

    /**
     * Handle Zeptomail open events.
     *
     * @param  array<string, mixed>  $data
     */
    private function handleZeptomailOpen(Request $request, array $data): void
    {
        // Extract request_id from webhook payload (at root level of event_message object)
        $requestId = is_string($data['request_id'] ?? null) ? $data['request_id'] : null;

        // Extract open details from event_data array
        $eventDataArray = is_array($data['event_data'] ?? null) ? $data['event_data'] : [];
        $eventData = is_array($eventDataArray[0] ?? null) ? $eventDataArray[0] : [];
        $detailsArray = is_array($eventData['details'] ?? null) ? $eventData['details'] : [];
        $details = is_array($detailsArray[0] ?? null) ? $detailsArray[0] : [];

        // Extract timestamp when user opened the email
        $openedAt = is_string($details['time'] ?? null) ? $details['time'] : null;

        // Find notification log (only update if not already opened)
        $notificationLog = null;
        if ($requestId) {
            $notificationLog = NotificationLog::where('message_id', $requestId)
                ->where('channel', 'mail')
                ->whereNull('opened_at')
                ->first();
        }

        // Fallback: try to match by recipient email if request_id not found
        if (! $notificationLog) {
            $recipientEmail = $this->extractRecipientEmail($data);

            if ($recipientEmail !== null) {
                $notificationLog = NotificationLog::where('channel', 'mail')
                    ->where('recipient', $recipientEmail)
                    ->whereNull('opened_at')
                    ->orderBy('sent_at', 'desc')
                    ->first();
            }
        }

        if (! $notificationLog) {
            Log::warning('[ZEPTOMAIL WEBHOOK] No notification log found for open event', [
                'request_id' => $requestId,
            ]);

            return;
        }

        // Use timestamp from webhook payload, or fallback to when we received the webhook
        $openedAtTimestamp = $openedAt !== null
            ? \Carbon\Carbon::parse($openedAt)
            : now();

        $notificationLog->update([
            'opened_at' => $openedAtTimestamp,
            'status' => 'opened',
            'http_headers_received' => $request->headers->all(),
            'gateway_response' => array_merge($notificationLog->gateway_response ?? [], ['open_event' => $data]),
        ]);

        Log::info('[ZEPTOMAIL WEBHOOK] Open event processed', [
            'notification_log_id' => $notificationLog->id,
        ]);
    }

    /**
     * Handle Zeptomail click events.
     *
     * @param  array<string, mixed>  $data
     */
    private function handleZeptomailClick(Request $request, array $data): void
    {
        // Extract request_id from webhook payload
        $requestId = is_string($data['request_id'] ?? null) ? $data['request_id'] : null;

        // Find notification log by message_id or fallback to email
        $notificationLog = $this->findNotificationLogByRequestIdOrEmail($data, $requestId);

        if (! $notificationLog) {
            Log::warning('[ZEPTOMAIL WEBHOOK] No notification log found for click event', [
                'request_id' => $requestId,
            ]);

            return;
        }

        // Store click event in gateway_response
        $notificationLog->update([
            'http_headers_received' => $request->headers->all(),
            'gateway_response' => array_merge($notificationLog->gateway_response ?? [], ['click_event' => $data]),
        ]);

        Log::info('[ZEPTOMAIL WEBHOOK] Click event processed', [
            'notification_log_id' => $notificationLog->id,
        ]);
    }

    /**
     * Find a notification log by request_id, with email fallback.
     *
     * @param  array<string, mixed>  $data
     */
    private function findNotificationLogByRequestIdOrEmail(array $data, ?string $requestId): ?NotificationLog
    {
        if ($requestId !== null) {
            $notificationLog = NotificationLog::where('message_id', $requestId)
                ->where('channel', 'mail')
                ->first();

            if ($notificationLog) {
                return $notificationLog;
            }
        }

        // Fallback: try to match by recipient email
        $recipientEmail = $this->extractRecipientEmail($data);

        if ($recipientEmail !== null) {
            return NotificationLog::where('channel', 'mail')
                ->where('recipient', $recipientEmail)
                ->orderBy('sent_at', 'desc')
                ->first();
        }

        return null;
    }

    /**
     * Extract recipient email address from Zeptomail webhook data.
     *
     * @param  array<string, mixed>  $data
     */
    private function extractRecipientEmail(array $data): ?string
    {
        $emailInfo = is_array($data['email_info'] ?? null) ? $data['email_info'] : [];
        $toArray = is_array($emailInfo['to'] ?? null) ? $emailInfo['to'] : [];
        $toFirst = is_array($toArray[0] ?? null) ? $toArray[0] : [];
        $emailAddress = is_array($toFirst['email_address'] ?? null) ? $toFirst['email_address'] : [];

        $address = $emailAddress['address'] ?? null;

        return is_string($address) ? $address : null;
    }

    /**
     * Verify Zeptomail webhook signature.
     *
     * Zeptomail uses 'producer-signature' header with format:
     * ts=<timestamp>;s=<signature>;s-algorithm=HmacSHA256
     *
     * @see https://www.zoho.com/zeptomail/help/webhooks.html#alink5
     */
    private function verifyZeptomailSignature(Request $request): bool
    {
        $configuredSecret = config('kraite.api.webhooks.zeptomail_secret');

        // Reject if no secret is configured - webhooks must always be authenticated
        if (! $configuredSecret) {
            Log::error('[ZEPTOMAIL WEBHOOK] No webhook secret configured');

            return false;
        }

        // Zeptomail uses 'producer-signature' header (updated webhook format)
        $signatureHeader = $request->header('producer-signature');

        if (! $signatureHeader) {
            return false;
        }

        // Parse signature header format: ts=<timestamp>;s=<signature>;s-algorithm=HmacSHA256
        $parts = [];
        foreach (explode(';', $signatureHeader) as $part) {
            if (!(str_contains(haystack: $part, needle: '='))) { continue; }

[$key, $value] = explode('=', $part, limit: 2);
                $parts[mb_trim($key)] = mb_trim($value);
        }

        $timestamp = $parts['ts'] ?? null;
        $signature = $parts['s'] ?? null;

        if (! $timestamp || ! $signature) {
            return false;
        }

        // URL decode the signature
        $signature = urldecode($signature);

        // Get raw request body
        $payload = $request->getContent();

        // Zeptomail signs ONLY the payload (not timestamp + payload as documented)
        // Calculate expected signature using HMAC-SHA256
        /** @var string $configuredSecret */
        $expectedSignature = base64_encode(hash_hmac('sha256', $payload, $configuredSecret, true));

        // Compare signatures (timing-safe)
        if (hash_equals($expectedSignature, $signature)) {
            return true;
        }

        Log::warning('[ZEPTOMAIL WEBHOOK] Signature mismatch', [
            'ip' => $request->ip(),
        ]);

        return false;
    }
}
