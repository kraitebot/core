<?php

declare(strict_types=1);

namespace Kraite\Core\Support;

use Kraite\Core\Enums\NotificationLogStatus;
use Kraite\Core\Models\NotificationLog;
use Kraite\Core\Models\Position;

final class PositionClosedNotifier
{
    private const array CANONICALS = [
        'position_closed',
        'position_high_profit_closed',
    ];

    /**
     * Send exactly one WAP-close notification after the exchange PnL exists.
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

        if ($position->getAttribute('pnl') === null || ! $position->was_waped) {
            return $notSent;
        }

        $user = $position->account->user;

        if ($user === null || ! $user->is_active || $this->wasAlreadySent($position, $user->id)) {
            return $notSent;
        }

        $filledLimitCount ??= $position->totalLimitOrdersFilled();
        $storedClosingPrice = $position->getAttribute('closing_price');
        $closingPrice ??= is_string($storedClosingPrice) ? $storedClosingPrice : null;
        $notifyThreshold = (int) $position->account->total_limit_orders_filled_to_notify;
        $isHighProfit = $notifyThreshold > 0 && $filledLimitCount >= $notifyThreshold;
        $canonical = $isHighProfit ? 'position_high_profit_closed' : 'position_closed';
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
        ];

        if (! $isHighProfit) {
            $referenceData['account_name'] = $position->account->name;
            $referenceData['was_fast_traded'] = (bool) $position->getAttribute('was_fast_traded');
        }

        $sent = NotificationService::send(
            user: $user,
            canonical: $canonical,
            referenceData: $referenceData,
            relatable: $position,
            cacheKeys: ['position' => $position->id],
        );

        return [
            'waped_closed_notification_sent' => $sent && ! $isHighProfit,
            'high_profit_notification_sent' => $sent && $isHighProfit,
        ];
    }

    private function wasAlreadySent(Position $position, int $userId): bool
    {
        return NotificationLog::query()
            ->whereIn('canonical', self::CANONICALS)
            ->where('user_id', $userId)
            ->where('relatable_type', $position->getMorphClass())
            ->where('relatable_id', $position->getKey())
            ->where('passed_threshold', true)
            ->where('status', '!=', NotificationLogStatus::Failed->value)
            ->exists();
    }
}
