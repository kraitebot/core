<?php

declare(strict_types=1);

namespace Kraite\Core\Observers;

use Kraite\Core\Models\ForbiddenHostname;
use Kraite\Core\Models\Engine;
use Kraite\Core\Support\NotificationService;
use Throwable;

final class ForbiddenHostnameObserver
{
    public function creating(ForbiddenHostname $model): void {}

    public function updating(ForbiddenHostname $model): void {}

    /**
     * Send notification when a new ForbiddenHostname is created.
     *
     * Routes notifications based on type:
     * - Admin: IP banned, IP rate limited (system-wide issues)
     * - User: IP not whitelisted, account blocked (account-specific issues)
     */
    public function created(ForbiddenHostname $model): void
    {
        $this->sendForbiddenNotification($model);
    }

    public function updated(ForbiddenHostname $model): void {}

    public function deleted(ForbiddenHostname $model): void {}

    public function forceDeleted(ForbiddenHostname $model): void {}

    /**
     * Send appropriate notification based on the blocking type.
     */
    private function sendForbiddenNotification(ForbiddenHostname $record): void
    {
        try {
            $hostname = gethostname();
            $apiSystem = $record->apiSystem;

            // Map type to notification canonical
            $canonical = match ($record->type) {
                ForbiddenHostname::TYPE_IP_NOT_WHITELISTED => 'server_ip_not_whitelisted',
                ForbiddenHostname::TYPE_IP_RATE_LIMITED => 'server_ip_rate_limited',
                ForbiddenHostname::TYPE_IP_BANNED => 'server_ip_banned',
                ForbiddenHostname::TYPE_ACCOUNT_BLOCKED => 'server_account_blocked',
                default => 'server_ip_forbidden',
            };

            // Determine who to notify based on type
            // Admin: system-wide issues (IP banned, IP rate limited)
            // User: account-specific issues (IP not whitelisted, account blocked)
            $notifyAdmin = in_array($record->type, [
                ForbiddenHostname::TYPE_IP_BANNED,
                ForbiddenHostname::TYPE_IP_RATE_LIMITED,
            ], strict: true);

            // Get the user to notify
            $user = $notifyAdmin
                ? Engine::admin()
                : $record->account?->user;

            // If no user to notify, skip notification
            if (! $user) {
                return;
            }

            NotificationService::send(
                user: $user,
                canonical: $canonical,
                referenceData: [
                    'type' => $record->type,
                    'exchange' => $apiSystem?->canonical,
                    'ip_address' => $record->ip_address,
                    'hostname' => $hostname,
                    'account_id' => $record->account_id,
                    'forbidden_until' => $record->forbidden_until?->toIso8601String(),
                    'error_code' => $record->error_code,
                    'error_message' => $record->error_message,
                ],
                relatable: $apiSystem,
                cacheKeys: [
                    'type' => $record->type,
                    'api_system' => $apiSystem?->canonical,
                    'ip_address' => $record->ip_address,
                    'account_id' => $record->account_id ?? 0,
                ]
            );
        } catch (Throwable $e) {
            // Fail silently - don't break the application if notification fails
        }
    }
}
