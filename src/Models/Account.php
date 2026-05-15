<?php

declare(strict_types=1);

namespace Kraite\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Kraite\Core\Abstracts\BaseModel;
use Kraite\Core\Concerns\Account\HasAccessors;
use Kraite\Core\Concerns\Account\HasCollections;
use Kraite\Core\Concerns\Account\HasGetters;
use Kraite\Core\Concerns\Account\HasScopes;
use Kraite\Core\Concerns\Account\HasStatuses;
use Kraite\Core\Concerns\Account\HasTokenDiscovery;
use Kraite\Core\Concerns\Account\InteractsWithApis;

/**
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property int $api_system_id
 * @property string $name
 * @property int $trade_configuration_id
 * @property string|null $portfolio_quote
 * @property string|null $trading_quote
 * @property float|null $margin
 * @property bool $can_trade
 * @property bool $is_active
 * @property bool $on_hedge_mode
 * @property int $position_leverage_long
 * @property int $position_leverage_short
 * @property string $margin_mode
 * @property string $margin_percentage_long
 * @property string $margin_percentage_short
 * @property int|null $last_notified_account_balance_history_id
 * @property array|null $credentials
 * @property array|null $credentials_testing
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
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read ApiSystem $apiSystem
 */
final class Account extends BaseModel
{
    use HasAccessors;
    use HasCollections;
    use HasFactory;
    use HasGetters;
    use HasScopes;
    use HasStatuses;
    use HasTokenDiscovery;
    use InteractsWithApis;
    use SoftDeletes;

    protected $casts = [
        'can_trade' => 'boolean',
        'is_active' => 'boolean',
        'on_hedge_mode' => 'boolean',
        'allow_other_positions' => 'boolean',
        'allow_other_orders' => 'boolean',
        'override_tp' => 'boolean',
        'override_sl' => 'boolean',
        'position_leverage_long' => 'integer',
        'position_leverage_short' => 'integer',
        'margin_mode' => 'string',
        'credentials' => 'array',
        'credentials_testing' => 'array',

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
        // Note: The following casts support Account::admin() in-memory instances
        // These columns don't exist in the accounts table (admin-only, stored in martingalian table)
        'coinmarketcap_api_key' => 'encrypted',
        'taapi_secret' => 'encrypted',
    ];

    /**
     * Credential columns excluded from any Eloquent serialization
     * (`toArray()`, `toJson()`, `jsonSerialize()`). Direct attribute access
     * (`$account->bitget_api_secret`) and the `all_credentials` accessor
     * still work — `$hidden` only filters serialization, which is the leak
     * path (`api_request_logs.payload` carrying the model JSON).
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
    ];

    /**
     * Create a temporary (non-persisted) Account instance with provided credentials.
     * This is the base method for creating in-memory accounts for testing or admin operations.
     *
     * @param  string  $apiSystemCanonical  API system canonical (e.g., 'binance', 'bybit')
     * @param  array  $credentials  Credentials array (e.g., ['binance_api_key' => '...', 'binance_api_secret' => '...'])
     * @return self Non-persisted Account instance
     */
    public static function temporary(string $apiSystemCanonical, array $credentials): self
    {
        $apiSystem = ApiSystem::where('canonical', $apiSystemCanonical)->firstOrFail();

        return tap(new self, static function (self $account) use ($credentials, $apiSystem) {
            // Fills encrypted columns via the mutator
            $account->all_credentials = $credentials;

            // Link to API system
            $account->api_system_id = $apiSystem->id;

            // Mark as non-persisted
            $account->exists = false;
        });
    }

    /**
     * Create temporary Account with admin credentials from martingalian table.
     * Convenience wrapper around temporary() that fetches system credentials.
     *
     * @param  string  $apiSystemCanonical  API system canonical (e.g., 'binance', 'bybit')
     * @return self Non-persisted Account instance with admin credentials
     */
    public static function admin(string $apiSystemCanonical): self
    {
        $source = Kraite::findOrFail(1);

        return self::temporary($apiSystemCanonical, $source->all_credentials);
    }

    /**
     * @return MorphMany<Step, $this>
     */
    public function steps(): MorphMany
    {
        return $this->morphMany(Step::class, 'relatable');
    }

    /**
     * @return MorphMany<ApiRequestLog, $this>
     */
    public function apiRequestLogs(): MorphMany
    {
        return $this->morphMany(ApiRequestLog::class, 'relatable');
    }

    /**
     * @return MorphMany<NotificationLog, $this>
     */
    public function notificationLogs(): MorphMany
    {
        return $this->morphMany(NotificationLog::class, 'relatable');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The 3-gate per-account readiness check used by
     * `CreatePositionsCommand` (and any future code path that decides
     * whether positions can open). All three must be true:
     *
     *   1. The account itself is `is_active=true && can_trade=true`
     *      (system-controlled — connectivity test gate).
     *   2. The user's pause switch is on (`users.can_trade=true`,
     *      user- or bot-controllable).
     *   3. The user's subscription is currently active (trial in
     *      progress OR wallet covers next renewal AND not paused AND
     *      renewal anchor in the future) — computed by
     *      `$user->billing()->subscription()->isActive()`.
     *
     * Global gates (BSCS, pump protection, exchange cooldown,
     * directional, slot count, dedupe) stay on the Kraite engine /
     * inline in the command — they're not per-account boolean state.
     */
    public function isReadyToTrade(): bool
    {
        if (! $this->is_active || ! $this->can_trade) {
            return false;
        }

        $user = $this->user;

        if ($user === null || ! $user->can_trade) {
            return false;
        }

        return $user->billing()->subscription()->isActive();
    }

    /**
     * @return BelongsTo<ApiSystem, $this>
     */
    public function apiSystem(): BelongsTo
    {
        return $this->belongsTo(ApiSystem::class);
    }

    /**
     * @return MorphMany<ApiSnapshot, $this>
     */
    public function apiSnapshots(): MorphMany
    {
        return $this->morphMany(ApiSnapshot::class, 'responsable');
    }

    /**
     * @return HasMany<Position, $this>
     */
    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    /**
     * @return HasMany<ForbiddenHostname, $this>
     */
    public function forbiddenHostnames(): HasMany
    {
        return $this->hasMany(ForbiddenHostname::class);
    }

    /**
     * @return HasMany<AccountBalanceHistory, $this>
     */
    public function balanceHistory(): HasMany
    {
        return $this->hasMany(AccountBalanceHistory::class);
    }

    /**
     * Source-of-truth balance figure for margin sizing.
     *
     * When the account is Kraite-exclusive (both `allow_other_*=false`)
     * the full wallet balance is fair game — Kraite knows everything
     * locked elsewhere. The helper returns `total-wallet-balance`
     * from the most recent `account-balance` snapshot stamped by
     * `StoreAccountBalanceJob`.
     *
     * When at least one `allow_other_*=true` (the operator may also
     * be placing positions or orders directly on the exchange) the
     * helper returns `available-balance` — the conservative figure
     * Binance reports as free margin remaining after locked-in
     * orders and initial margin of all open positions. Avoids
     * blowing the margin ratio when Kraite cannot see what the
     * operator is doing.
     *
     * Cold-start fallback: when no balance snapshot exists yet, or
     * the snapshot is missing the expected key, the helper falls
     * back to the account's persisted `margin` column.
     */
    public function balanceForTrading(): string
    {
        $key = ($this->allow_other_positions || $this->allow_other_orders)
            ? 'available-balance'
            : 'total-wallet-balance';

        $snapshot = ApiSnapshot::getFrom($this, 'account-balance');

        $value = $snapshot[$key] ?? null;

        if ($value === null || $value === '') {
            return (string) ($this->margin ?? '0');
        }

        return (string) $value;
    }

    /**
     * @return BelongsTo<TradeConfiguration, $this>
     */
    public function tradeConfiguration(): BelongsTo
    {
        return $this->belongsTo(TradeConfiguration::class);
    }

    protected static function newFactory()
    {
        return \Kraite\Core\Database\Factories\AccountFactory::new();
    }
}
