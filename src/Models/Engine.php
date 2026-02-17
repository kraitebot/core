<?php

declare(strict_types=1);

namespace Kraite\Core\Models;



use Kraite\Core\Abstracts\BaseModel;
use Kraite\Core\Concerns\Engine\HasAccessors;
use Kraite\Core\Concerns\Engine\HasGetters;


/**
 * @property int $id
 * @property bool $allow_opening_positions
 * @property bool $is_cooling_down
 * @property string|null $binance_api_key
 * @property string|null $binance_api_secret
 * @property string|null $bybit_api_key
 * @property string|null $bybit_api_secret
 * @property string|null $kraken_api_key
 * @property string|null $kraken_private_key
 * @property string|null $kucoin_api_key
 * @property string|null $kucoin_api_secret
 * @property string|null $kucoin_passphrase
 * @property string|null $bitget_api_key
 * @property string|null $bitget_api_secret
 * @property string|null $bitget_passphrase
 * @property string|null $coinmarketcap_api_key
 * @property string|null $taapi_secret
 * @property string|null $admin_pushover_user_key
 * @property string|null $admin_pushover_application_key
 * @property string|null $email
 * @property array<int, string> $notification_channels
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
final class Engine extends BaseModel
{
    use HasAccessors;
    use HasGetters;

    protected $table = 'martingalian';

    protected $casts = [
        'allow_opening_positions' => 'boolean',
        'is_cooling_down' => 'boolean',

        'binance_api_key' => 'encrypted',
        'binance_api_secret' => 'encrypted',
        'bybit_api_key' => 'encrypted',
        'bybit_api_secret' => 'encrypted',
        'kraken_api_key' => 'encrypted',
        'kraken_private_key' => 'encrypted',
        'kucoin_api_key' => 'encrypted',
        'kucoin_api_secret' => 'encrypted',
        'kucoin_passphrase' => 'encrypted',
        'bitget_api_key' => 'encrypted',
        'bitget_api_secret' => 'encrypted',
        'bitget_passphrase' => 'encrypted',
        'coinmarketcap_api_key' => 'encrypted',
        'taapi_secret' => 'encrypted',
        'admin_pushover_user_key' => 'encrypted',
        'admin_pushover_application_key' => 'encrypted',

        'notification_channels' => 'array',
    ];

    /**
     * Get the current server's public IP address from the servers table.
     * Falls back to gethostbyname if server not found.
     */
    public static function ip(): string
    {
        $hostname = gethostname();

        $server = Server::where('hostname', $hostname)->first();

        if ($server && $server->ip_address) {
            return $server->ip_address;
        }

        // Fallback for unknown servers
        return gethostbyname($hostname);
    }
}
