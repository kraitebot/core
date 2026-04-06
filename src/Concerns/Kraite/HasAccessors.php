<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns\Kraite;

trait HasAccessors
{
    public function getAllCredentialsAttribute(): array
    {
        return [
            'binance_api_key' => $this->binance_api_key,
            'binance_api_secret' => $this->binance_api_secret,
            'bybit_api_key' => $this->bybit_api_key,
            'bybit_api_secret' => $this->bybit_api_secret,
            'kraken_api_key' => $this->kraken_api_key,
            'kraken_private_key' => $this->kraken_private_key,
            'coinmarketcap_api_key' => $this->coinmarketcap_api_key,
            'taapi_secret' => $this->taapi_secret,
        ];
    }
}
