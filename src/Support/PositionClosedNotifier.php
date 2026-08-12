<?php

declare(strict_types=1);

namespace Kraite\Core\Support;

use Kraite\Core\Enums\NotificationLogStatus;
use Kraite\Core\Models\NotificationLog;
use Kraite\Core\Models\Position;
use Kraite\Core\Notifications\Channels\AppPushChannel;

final class PositionClosedNotifier
{
    /**
     * Notify the trader in-app when a penultimate-limit position closes with exchange PnL.
     *
     * @return array{waped_closed_notification_sent: bool, high_profit_notification_sent: bool}
     */
    public function send(
        Position $position,
        ?string $closingPrice = null,
        ?int $filledLimitCount = null,
    ): array {
        $notSent = [
            'waped_closed_notification_sent' => false,
            'high_profit_notification_sent' => false,
        ];

        $position->loadMissing(['account.user', 'exchangeSymbol']);

        if ($position->getAttribute('pnl') === null) {
            return $notSent;
        }

        $filledLimitCount ??= $position->totalLimitOrdersFilled();

        if (! $position->hasFilledPenultimateLimitOrder($filledLimitCount)) {
            return $notSent;
        }

        $user = $position->account->user;

        if ($user === null || ! $user->is_active || $this->wasAlreadySent($position, $user->id)) {
            return $notSent;
        }

        $manuallyClosed = $position->manually_closed_at !== null;
        $storedClosingPrice = $position->getAttribute('closing_price');
        $closingPrice ??= is_string($storedClosingPrice) ? $storedClosingPrice : null;
        $pair = $position->getAttribute('parsed_trading_pair');

        $referenceData = [
            'token' => $position->exchangeSymbol->token,
            'pair' => is_string($pair) ? $pair : null,
            'direction' => mb_strtoupper((string) $position->direction),
            'position_id' => (int) $position->id,
            'closing_price' => $closingPrice === null
                ? null
                : api_format_price($closingPrice, $position->exchangeSymbol),
            'filled_limits' => $filledLimitCount,
            'manually_closed' => $manuallyClosed,
        ];

        $sent = NotificationService::send(
            user: $user,
            canonical: 'position_high_profit_closed',
            referenceData: $referenceData,
            relatable: $position,
            cacheKeys: ['position' => $position->id],
            channels: [AppPushChannel::class],
        );

        return [
            'waped_closed_notification_sent' => false,
            'high_profit_notification_sent' => $sent,
        ];
    }

    private function wasAlreadySent(Position $position, int $userId): bool
    {
        return NotificationLog::query()
            ->where('canonical', 'position_high_profit_closed')
            ->where('user_id', $userId)
            ->where('relatable_type', $position->getMorphClass())
            ->where('relatable_id', $position->getKey())
            ->where('passed_threshold', true)
            ->where('status', '!=', NotificationLogStatus::Failed->value)
            ->exists();
    }
}
