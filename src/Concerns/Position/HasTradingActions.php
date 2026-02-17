<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns\Position;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Kraite\Core\Models\ApiSnapshot;
use Kraite\Core\Support\Proxies\ApiDataMapperProxy;

trait HasTradingActions
{
    public function getParsedTradingPair(): ?string
    {
        if (! $this->exchangeSymbol?->token) {
            return null;
        }

        $apiSystem = $this->account->apiSystem;

        $dataMapper = new ApiDataMapperProxy($apiSystem->canonical);

        // Token and quote are stored directly on exchange_symbols
        return $dataMapper->baseWithQuote($this->exchangeSymbol->token, $this->exchangeSymbol->quote);
    }

    public function isOpenedOnExchange()
    {
        $openPositions = ApiSnapshot::getFrom($this->account, 'account-positions');

        return is_array($openPositions) && array_key_exists(key: $this->parsed_trading_pair, array: $openPositions);
    }

    public function syncOrders()
    {
        $this->orders->whereNotNull('exchange_order_id')->each->apiSync();
    }

    public function opened_since(): ?string
    {
        $openedAt = $this->opened_at ?? $this->created_at ?? null;
        if (! $openedAt) {
            return null;
        }

        $opened = $openedAt instanceof Carbon ? $openedAt : Carbon::parse($openedAt);

        return $opened->diffForHumans(now(), [
            'parts' => 1,
            'short' => true,
            'syntax' => CarbonInterface::DIFF_RELATIVE_TO_NOW,
        ]);
    }
}
