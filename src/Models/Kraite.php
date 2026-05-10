<?php

declare(strict_types=1);

namespace Kraite\Core\Models;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Kraite\Core\Abstracts\BaseModel;
use Kraite\Core\Concerns\Kraite\HasAccessors;
use Kraite\Core\Concerns\Kraite\HasGetters;
use RuntimeException;

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
 * @property array<int, string>|null $timeframes
 * @property string $top_up_minimum_when_covered_usdt
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
final class Kraite extends BaseModel
{
    use HasAccessors;
    use HasGetters;

    public const IP_CACHE_KEY = 'kraite.server.public_ip';

    public const IP_CACHE_TTL_SECONDS = 86400;

    protected $table = 'kraite';

    protected $casts = [
        'allow_opening_positions' => 'boolean',
        'is_cooling_down' => 'boolean',

        'top_up_minimum_when_covered_usdt' => 'decimal:4',

        // BSCS (Black Swan Composite Score) — Phase 1 telemetry.
        // Phase 2 wires `bscs_block_active` into HasTradingGuards.
        'bscs_score' => 'integer',
        'bscs_synced_at' => 'datetime',
        'bscs_block_active' => 'boolean',
        'bscs_block_threshold' => 'integer',
        'bscs_freshness_max_seconds' => 'integer',
        'bscs_override_until' => 'datetime',
        'bscs_cooldown_until' => 'datetime',

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
        'nowpayments_api_key' => 'encrypted',
        'nowpayments_ipn_secret' => 'encrypted',
        'resend_api_key' => 'encrypted',
        'admin_pushover_user_key' => 'encrypted',
        'admin_pushover_application_key' => 'encrypted',

        'notification_channels' => 'array',
        'timeframes' => 'array',
    ];

    /**
     * Credential columns excluded from any Eloquent serialization
     * (`toArray()`, `toJson()`, `jsonSerialize()`). Direct attribute
     * access still works — `$hidden` only filters serialization, which is
     * the leak path (`api_request_logs.payload` carrying the model JSON
     * via `Account::admin()` calls that hydrate from `kraite.all_credentials`).
     */
    protected $hidden = [
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
        'admin_pushover_user_key',
        'admin_pushover_application_key',
    ];

    /**
     * Global indicator/kline timeframe list. Single source for every
     * consumer that used to read `$apiSystem->timeframes`.
     *
     * Memoised via `once()` so callers inside hot loops (candidate
     * scoring in `HasTokenDiscovery::selectBestTokenByBtcBias`, per-
     * exchange iteration in `FetchKlinesCommand::handleBulkSymbols`,
     * etc.) see a single `SELECT * FROM kraite` per PHP process.
     * Matches the style of `Kraite::admin()`.
     *
     * Returns an empty array when no kraite row exists yet (fresh
     * install mid-seed) so callers can guard cleanly without null
     * checks.
     *
     * @return array<int, string>
     */
    public static function timeframes(): array
    {
        return once(fn (): array => self::first()?->timeframes ?? []);
    }

    /**
     * Current server's public IP.
     *
     * Primary source is the `servers` row matching the OS hostname — one
     * local DB roundtrip, authoritative. The previous fallback used
     * `gethostbyname()`, which on Ubuntu boxes resolves the hostname to
     * `127.0.1.1` via `/etc/hosts` and poisoned every IP-scoped downstream
     * check (ForbiddenHostname lookups, API-key whitelist diagnostics,
     * per-IP rate-limit ledgers). If the DB row is missing or misconfigured
     * we resolve via an external echo service and cache the answer for a
     * day — the public IP of a long-running server very rarely moves.
     */
    public static function ip(): string
    {
        $hostname = gethostname();

        if ($hostname !== false) {
            $server = Server::where('hostname', $hostname)->first();

            if ($server && $server->ip_address) {
                return $server->ip_address;
            }
        }

        return Cache::remember(
            self::IP_CACHE_KEY,
            self::IP_CACHE_TTL_SECONDS,
            static function (): string {
                $response = Http::timeout(5)->get('https://api.ipify.org', ['format' => 'text']);
                $ip = $response->successful() ? mb_trim($response->body()) : '';

                if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    throw new RuntimeException('Unable to resolve the server public IP via the external resolver.');
                }

                return $ip;
            }
        );
    }
}
