<?php

declare(strict_types=1);

namespace Kraite\Core\Observers;

use Kraite\Core\Enums\NotificationLogStatus;
use Kraite\Core\Models\NotificationLog;
use Kraite\Core\Models\User;
use NotificationChannels\Pushover\PushoverChannel;

final class NotificationLogObserver
{
    /**
     * Handle the NotificationLog "updated" event.
     * Triggers bounce alert notifications and manages user behaviour flags.
     */
    public function updated(NotificationLog $notificationLog): void
    {
        // Only process mail channel bounces
        if ($notificationLog->channel !== 'mail') {
            return;
        }

        // Check if status changed to soft or hard bounce
        if ($notificationLog->wasChanged('status')) {
            $newStatus = $notificationLog->status;
            $originalStatus = $notificationLog->getOriginal('status');

            $bounceValues = [
                NotificationLogStatus::SoftBounced->value,
                NotificationLogStatus::HardBounced->value,
            ];
            $recoveryValues = [
                NotificationLogStatus::Delivered->value,
                NotificationLogStatus::Opened->value,
            ];

            // Handle bounce detection (status changed TO bounce)
            if (in_array($newStatus, $bounceValues, true)) {
                $this->handleBounceDetection($notificationLog);
            }

            // Handle bounce recovery (status changed FROM bounce TO delivered/opened)
            if (in_array($originalStatus, $bounceValues, true)
                && in_array($newStatus, $recoveryValues, true)) {
                $this->handleBounceRecovery($notificationLog);
            }
        }
    }

    /**
     * Handle bounce detection - send alert and set behaviour flag.
     */
    private function handleBounceDetection(NotificationLog $notificationLog): void
    {
        // Find user by recipient email
        $user = User::where('email', $notificationLog->recipient)->first();

        // Skip if user not found or is virtual admin
        if (! $user || $user->is_virtual) {
            return;
        }

        // ALWAYS set the behaviour flag regardless of whether we send notification
        $behaviours = $user->behaviours ?? [];
        $behaviours['should_announce_bounced_email'] = true;
        $user->behaviours = $behaviours;
        $user->save();

        // Skip notification if user doesn't have pushover_key
        if (! $user->pushover_key) {
            return;
        }

        // Save original notification channels
        $originalChannels = $user->notification_channels;

        // Temporarily replace channels with ONLY Pushover (to avoid mail bounce loop)
        $user->notification_channels = [PushoverChannel::class];
        $user->save();

        // Removed bounce_alert_to_pushover notification - invalid canonical

        // Restore original notification channels
        $user->notification_channels = $originalChannels;
        $user->save();
    }

    /**
     * Handle bounce recovery - clear behaviour flag.
     */
    private function handleBounceRecovery(NotificationLog $notificationLog): void
    {
        // Find user by recipient email
        $user = User::where('email', $notificationLog->recipient)->first();

        if (! $user) {
            return;
        }

        // Clear the behaviour flag
        $behaviours = $user->behaviours ?? [];
        unset($behaviours['should_announce_bounced_email']);
        $user->behaviours = $behaviours;
        $user->save();
    }
}
