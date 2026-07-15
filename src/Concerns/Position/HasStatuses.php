<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns\Position;

use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Support\NotificationService;

trait HasStatuses
{
    public function isActive()
    {
        return ! in_array($this->status, ['closed', 'cancelled']);
    }

    public function isClosing()
    {
        return $this->status === 'closing';
    }

    public function isCancelled()
    {
        return $this->status === 'cancelled';
    }

    public function updateToWatching()
    {
        $this->updateSaving(['status' => 'watching']);
    }

    public function updateToWaping()
    {
        $this->updateSaving(['status' => 'waping']);
    }

    public function updateToOpening()
    {
        $this->updateSaving(['status' => 'opening']);
    }

    public function updateToCancelling()
    {
        $this->updateSaving(['status' => 'cancelling']);
    }

    public function updateToActive()
    {
        $this->updateSaving(['status' => 'active']);
    }

    public function updateToSyncing()
    {
        $this->updateSaving(['status' => 'syncing']);
    }

    public function updateToClosing()
    {
        $this->updateSaving(['status' => 'closing']);
    }

    public function updateToClosed()
    {
        $this->updateSaving([
            'closed_at' => now(),
            'status' => 'closed',
            'error_message' => null, // Clear any previous errors on successful close
        ]);
    }

    public function updateToCancelled(?string $message = null): void
    {
        $data = ['status' => 'cancelled'];

        if ($this->opened_at !== null) {
            $data['closed_at'] = now();
        }

        if ($message !== null) {
            $data['error_message'] = $message;
        }

        $this->updateSaving($data);
    }

    public function updateToFailed(?string $message = null): void
    {
        $isTransitionIntoFailed = $this->status !== 'failed';

        $data = ['status' => 'failed'];

        if ($this->opened_at !== null) {
            $data['closed_at'] = now();
        }

        if ($message !== null) {
            $data['error_message'] = $message;
        }

        $this->updateSaving($data);

        // Side-effects fire only on the *transition* into failed. Re-entries
        // via retried sync paths must not double-ping the operator or
        // repeatedly apply the symbol's automatic safety gate.
        if (! $isTransitionIntoFailed) {
            return;
        }

        $tokenBlocked = $this->blockSymbolAfterOpeningFailure();
        $this->dispatchOpeningFailedNotification($message, $tokenBlocked);
    }

    /**
     * Apply an automatic selection block so the scheduler stops picking the
     * same broken token. The sysadmin-owned manual flag remains untouched.
     */
    private function blockSymbolAfterOpeningFailure(): bool
    {
        $exchangeSymbol = $this->exchangeSymbol;

        if ($exchangeSymbol === null) {
            return false;
        }

        return $exchangeSymbol->applySystemBlock(ExchangeSymbol::SYSTEM_BLOCK_OPENING_FAILED);
    }

    private function dispatchOpeningFailedNotification(?string $message, bool $tokenBlocked): void
    {
        $user = $this->account->user ?? null;

        if (! $user || ! $user->is_active) {
            return;
        }

        NotificationService::send(
            user: $user,
            canonical: 'position_opening_failed',
            referenceData: [
                'token' => $this->exchangeSymbol?->token,
                'pair' => $this->parsed_trading_pair,
                'direction' => mb_strtoupper((string) $this->direction),
                'position_id' => (int) $this->id,
                'reason' => $message ?? 'Unknown error',
                'token_blocked' => $tokenBlocked,
            ],
            relatable: $this,
            // Throttle key is per-account (matches the notification's
            // cache_key=['account'] / 3600s): a sustained opening outage
            // collapses to one alert/hour instead of one per failed
            // position id. The failing position's identity still rides in
            // referenceData for the operator.
            cacheKeys: ['account' => $this->account_id],
        );
    }
}
