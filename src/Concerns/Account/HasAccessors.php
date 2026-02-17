<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns\Account;

use ArrayAccess;
use Illuminate\Support\Arr;

trait HasAccessors
{
    /**
     * Aggregate credentials from concrete columns.
     * Uses getAttribute to properly decrypt encrypted columns.
     * Returns null for attributes that don't exist in the database.
     *
     * @return array<string,string|null>
     */
    public function getAllCredentialsAttribute(): array
    {
        $keys = [
            'binance_api_key',
            'binance_api_secret',
            'bybit_api_key',
            'bybit_api_secret',
            'kraken_api_key',
            'kraken_private_key',
            'kucoin_api_key',
            'kucoin_api_secret',
            'kucoin_passphrase',
            'bitget_api_key',
            'bitget_api_secret',
            'bitget_passphrase',
            'coinmarketcap_api_key',
            'taapi_secret',
        ];

        $credentials = [];

        foreach ($keys as $key) {
            if (array_key_exists(key: $key, array: $this->attributes)) {
                $credentials[$key] = $this->getAttributeValue($key);
            } else {
                $credentials[$key] = null;
            }
        }

        return $credentials;
    }

    /**
     * Fan-in mutator: assign a full credentials array and populate the real columns.
     * This lets you do: $account->all_credentials = $someArray;
     *
     * Important:
     * - This uses setAttribute() so your 'encrypted' casts run automatically.
     * - Absent keys are ignored (no overwrites); pass only the keys you intend to set.
     *
     * @param  array|ArrayAccess  $value
     */
    public function setAllCredentialsAttribute($value): void
    {
        $keys = [
            'binance_api_key',
            'binance_api_secret',
            'bybit_api_key',
            'bybit_api_secret',
            'kraken_api_key',
            'kraken_private_key',
            'kucoin_api_key',
            'kucoin_api_secret',
            'kucoin_passphrase',
            'bitget_api_key',
            'bitget_api_secret',
            'bitget_passphrase',
            'coinmarketcap_api_key',
            'taapi_secret',
        ];

        foreach ($keys as $key) {
            if (!(Arr::has($value, $key))) { continue; }

$this->setAttribute($key, Arr::get($value, $key));
        }
    }

}
