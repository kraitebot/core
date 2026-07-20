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
            'kucoin_api_key' => $this->kucoin_api_key,
            'kucoin_api_secret' => $this->kucoin_api_secret,
            'kucoin_passphrase' => $this->kucoin_passphrase,
            'bitget_api_key' => $this->bitget_api_key,
            'bitget_api_secret' => $this->bitget_api_secret,
            'bitget_passphrase' => $this->bitget_passphrase,
            'coinmarketcap_api_key' => $this->coinmarketcap_api_key,
            'taapi_secret' => $this->taapi_secret,
        ];
    }
}
