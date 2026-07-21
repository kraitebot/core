<?php

declare(strict_types=1);

namespace Kraite\Core\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Kraite\Core\Enums\NotificationLogStatus;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\NotificationLog;
use Throwable;

final class RegistrationWelcomeNotifier
{
    private const string CANONICAL = 'registration_welcome';

    public function afterAccountActivated(Account $account): void
    {
        $accountId = (int) $account->getKey();

        try {
            DB::afterCommit(function () use ($accountId): void {
                try {
                    $account = Account::query()
                        ->with(['apiSystem', 'user'])
                        ->find($accountId);

                    if ($account !== null) {
                        $this->send($account);
                    }
                } catch (Throwable $e) {
                    $this->reportFailure($accountId, 'delivery', $e);
                }
            });
        } catch (Throwable $e) {
            $this->reportFailure($accountId, 'scheduling', $e);
        }
    }

    public function send(Account $account): bool
    {
        $account->loadMissing(['apiSystem', 'user']);

        if (! $account->isReadyToTrade()) {
            return false;
        }

        $user = $account->user;

        if ($user === null) {
            return false;
        }

        if (NotificationLog::query()
            ->byCanonical(self::CANONICAL)
            ->where('user_id', $user->getKey())
            ->byChannel('mail')
            ->where('status', '!=', NotificationLogStatus::Failed->value)
            ->exists()) {
            return false;
        }

        return NotificationService::send(
            user: $user,
            canonical: self::CANONICAL,
            referenceData: [
                'apiSystem' => $account->apiSystem,
                'has_existing_activity' => $account->allow_other_positions || $account->allow_other_orders,
                'dashboard_url' => rtrim((string) config('kraite.admin_url'), '/'),
            ],
            relatable: $account,
            duration: 0,
            channels: ['mail'],
        );
    }

    private function reportFailure(int $accountId, string $stage, Throwable $e): void
    {
        Log::channel('jobs')->warning('[RegistrationWelcomeNotifier] welcome notification failed — activation will continue', [
            'account_id' => $accountId,
            'stage' => $stage,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
    }
}
