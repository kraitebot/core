<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns\ExchangeSymbol;

use Exception;
use Illuminate\Support\Carbon;
use Kraite\Core\Models\Engine;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\NotificationService;
use Kraite\Core\Support\Proxies\TradingMapperProxy;

/**
 * SendsNotifications
 *
 * Handles delisting notification logic for ExchangeSymbol.
 * This is the single source of truth for symbol delisting notifications.
 */
trait SendsNotifications
{
    /**
     * Send delisting notification if delivery date changed in a way that indicates delisting.
     * Called by ExchangeSymbolObserver::saved() after the symbol is saved.
     *
     * Exchange-specific logic is delegated to TradingMapper classes.
     */
    public function sendDelistingNotificationIfNeeded(): void
    {
        $canonical = $this->apiSystem->canonical ?? null;

        if (! $canonical) {
            return;
        }

        try {
            $mapper = new TradingMapperProxy($canonical);

            if ($mapper->isNowDelisted($this)) {
                $this->sendDelistingNotification($this->delivery_ts_ms);
            }
        } catch (Exception $e) {
            // Unsupported exchange - no delisting detection
        }
    }

    /**
     * Send complete delisting notification with position details to admin.
     *
     * @param  int  $deliveryTimestampMs  The delivery timestamp in milliseconds
     */
    protected function sendDelistingNotification(int $deliveryTimestampMs): void
    {
        // Get symbol information
        $pairText = $this->parsed_trading_pair ?? 'N/A';
        $deliveryDate = Carbon::createFromTimestampMs($deliveryTimestampMs)->utc()->format('j M Y H:i');

        // Find all open positions for this exchange symbol
        $positions = Position::query()
            ->opened()
            ->where('exchange_symbol_id', $this->id)
            ->whereHas('account', function ($q) {
                $q->where('api_system_id', $this->api_system_id);
            })
            ->get();

        // Build position details string
        $positionsDetails = '';
        if ($positions->isNotEmpty()) {
            foreach ($positions as $position) {
                $account = $position->account;
                $user = $account->user;
                $userName = $user ? $user->name : 'No User Assigned';
                $accountName = $account->name ?? "Account #{$account->id}";
                $direction = mb_strtoupper((string) $position->direction);

                $positionsDetails .= sprintf(
                    "• Position #%d (%s)\n  Account: %s\n  User: %s\n\n",
                    $position->id,
                    $direction,
                    $accountName,
                    $userName
                );
            }
        }

        // Send notification using NotificationService with throttling
        NotificationService::send(
            user: Engine::admin(),
            canonical: 'token_delisting',
            referenceData: [
                'apiSystem' => $this->apiSystem,
                'exchangeSymbol' => $this,
                'pair_text' => $pairText,
                'delivery_date' => $deliveryDate,
                'positions_count' => $positions->count(),
                'positions_details' => $positionsDetails,
            ],
            relatable: $this,
            cacheKeys: [
                'exchange_symbol' => $this->id,
            ]
        );
    }
}
