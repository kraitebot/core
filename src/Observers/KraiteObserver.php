<?php

declare(strict_types=1);

namespace Kraite\Core\Observers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Kraite\Core\Models\Kraite;
use Throwable;

/**
 * KraiteObserver
 *
 * Watches the engine singleton for telegram pairing transitions.
 * The engine row carries the ADMIN's `admin_telegram_chat_id`
 * (parallel to a regular user's `telegram_chat_id`); when that
 * column moves from null/empty to a concrete chat_id, the bot
 * sends the same one-off welcome message that `UserObserver`
 * fires for regular users.
 *
 * Same one-way / failure-contained shape as the user-side
 * implementation. Token missing → silent skip. Telegram 401 /
 * timeout → logged + swallowed.
 */
final class KraiteObserver
{
    private const string TELEGRAM_API_BASE = 'https://api.telegram.org';

    private const string WELCOME_BODY = "✅ <b>Welcome to Kraite — Admin</b>\n\n".
        "Notifications channel paired.\n\n".
        "<i>This is a one-way channel — alerts only. Replies aren't read. ".
        "Reach support via the admin web instead.</i>";

    public function created(Kraite $model): void
    {
        $this->sendTelegramWelcomeIfPaired($model);
    }

    public function updated(Kraite $model): void
    {
        if ($model->wasChanged('admin_telegram_chat_id')) {
            $this->sendTelegramWelcomeIfPaired($model);
        }
    }

    private function sendTelegramWelcomeIfPaired(Kraite $model): void
    {
        $chatId = $model->admin_telegram_chat_id;

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
            Log::warning('[KraiteObserver] Telegram welcome send failed; swallowed', [
                'kraite_id' => $model->id,
                'chat_id' => $chatId,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
