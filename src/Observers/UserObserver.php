<?php

declare(strict_types=1);

namespace Kraite\Core\Observers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Kraite\Core\Models\User;
use Throwable;

final class UserObserver
{
    private const string TELEGRAM_API_BASE = 'https://api.telegram.org';

    private const string WELCOME_BODY = "✅ <b>Welcome to Kraite</b>\n\n".
        "Notifications channel paired.\n\n".
        "<i>This is a one-way channel — alerts only. Replies aren't read. ".
        "Reach support via the admin web instead.</i>";

    public function creating(User $model): void
    {
        // $model->cacheChangesForCreate();
    }

    public function created(User $model): void
    {
        $this->sendTelegramWelcomeIfPaired($model);
    }

    public function updating(User $model): void
    {
        // $model->cacheChangesForUpdate();

        // Clear bounce behaviour flag when email changes
        if ($model->isDirty('email')) {
            $behaviours = $model->behaviours ?? [];
            unset($behaviours['should_announce_bounced_email']);
            $model->behaviours = $behaviours;
        }
    }

    public function updated(User $model): void
    {
        if ($model->wasChanged('telegram_chat_id')) {
            $this->sendTelegramWelcomeIfPaired($model);
        }
    }

    public function deleted(User $model): void {}

    public function forceDeleted(User $model): void {}

    /**
     * Fire the one-off Telegram welcome when a user first pairs the bot
     * (i.e. their `telegram_chat_id` transitions from null/empty to a
     * concrete chat_id). The message itself states the channel is
     * one-way so the user doesn't waste time DM-ing back.
     *
     * Uses a direct `sendMessage` HTTP call rather than the full
     * `NotificationService` canonical pipeline because this is
     * intrinsically once-per-user and outside the throttle/severity
     * model — it's a pairing acknowledgement, not an alert. Failures
     * are logged + swallowed so a bad token / network blip can't
     * abort the surrounding user save.
     */
    private function sendTelegramWelcomeIfPaired(User $model): void
    {
        $chatId = $model->telegram_chat_id;

        if (! $chatId) {
            return;
        }

        $token = config('services.telegram-bot-api.token');

        if (! $token) {
            return;
        }

        try {
            Http::asForm()
                ->post(self::TELEGRAM_API_BASE."/bot{$token}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => self::WELCOME_BODY,
                    'parse_mode' => 'HTML',
                ]);
        } catch (Throwable $exception) {
            Log::warning('[UserObserver] Telegram welcome send failed; swallowed', [
                'user_id' => $model->id,
                'chat_id' => $chatId,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
