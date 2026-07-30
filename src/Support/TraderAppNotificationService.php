<?php

declare(strict_types=1);

namespace Kraite\Core\Support;

use Illuminate\Support\Facades\Log;
use Kraite\Core\Models\User;
use Kraite\Core\Notifications\Channels\AppPushChannel;
use Throwable;

final class TraderAppNotificationService
{
    /**
     * @param  array<string, mixed>  $referenceData
     */
    public static function send(
        string $canonical,
        array $referenceData = [],
        ?object $relatable = null,
    ): int {
        $sent = 0;

        try {
            User::query()
                ->where('is_active', true)
                ->whereHas('accounts')
                ->chunkById(100, function ($users) use ($canonical, $referenceData, $relatable, &$sent): void {
                    foreach ($users as $user) {
                        if (NotificationService::send(
                            user: $user,
                            canonical: $canonical,
                            referenceData: $referenceData,
                            relatable: $relatable,
                            duration: 0,
                            channels: [AppPushChannel::class],
                        )) {
                            $sent++;
                        }
                    }
                });
        } catch (Throwable $exception) {
            Log::warning('[TraderAppNotificationService] fan-out failed; trading state was preserved', [
                'canonical' => $canonical,
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);
        }

        return $sent;
    }
}
