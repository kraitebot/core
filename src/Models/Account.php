<?php

declare(strict_types=1);

namespace Kraite\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Kraite\Core\Abstracts\BaseModel;
use Kraite\Core\Concerns\Account\HasAccessors;
use Kraite\Core\Concerns\Account\HasCollections;
use Kraite\Core\Concerns\Account\HasGetters;
use Kraite\Core\Concerns\Account\HasScopes;
use Kraite\Core\Concerns\Account\HasStatuses;
use Kraite\Core\Concerns\Account\HasTokenDiscovery;
use Kraite\Core\Concerns\Account\InteractsWithApis;
use Kraite\Core\Database\Factories\AccountFactory;

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
 * @property string $balance_for_trading_basis
 * @property bool $can_trade
 * @property bool $is_active
 * @property bool $on_hedge_mode
 * @property bool|null $use_correlation_sign_filter
 * @property bool|null $use_btc_bias_restriction
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
 * @property string|null $bitget_account_mode
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
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
        'use_correlation_sign_filter' => 'boolean',
        'use_btc_bias_restriction' => 'boolean',
        'override_tp' => 'boolean',
        'override_sl' => 'boolean',
        'balance_for_trading_basis' => 'string',
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
     * The central per-account readiness check used before dispatching
     * workflows that can open positions. Every gate must be true:
     *
     *   1. The account itself is `is_active=true && can_trade=true`
     *      (system-controlled — connectivity test gate).
     *   2. The account's API system is active.
     *   3. The user is active and their pause switch is on
     *      (`users.is_active=true && users.can_trade=true`).
     *   4. The user's subscription is currently active (trial in
     *      progress, a paid renewal anchor is still in the future, or
     *      a free tier applies; always not paused) — computed by
     *      `$user->billing()->subscription()->isActive()`.
     *   5. On a one-account tier, this account is the user's explicitly
     *      designated active account.
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

        if (! $this->isOnActiveApiSystem()) {
            return false;
        }

        $user = $this->user;

        if ($user === null || ! $user->is_active || ! $user->can_trade) {
            return false;
        }

        if (! $user->billing()->subscription()->isActive()) {
            return false;
        }

        $tier = $user->subscription;

        if ($tier !== null && ! $tier->hasUnlimitedAccounts() && $tier->max_accounts === 1) {
            return $user->active_account_id !== null
                && (int) $user->active_account_id === (int) $this->id;
        }

        return true;
    }

    public function isOnActiveApiSystem(): bool
    {
        return $this->apiSystem?->is_active === true;
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
     * Quote currency used for portfolio-level balance queries.
     */
    public function balanceQuote(): string
    {
        $quote = $this->portfolio_quote ?: $this->trading_quote ?: 'USDT';

        return mb_strtoupper((string) $quote);
    }

    /**
     * Source-of-truth balance figure for margin sizing.
     *
     * `balance_for_trading_basis=total` uses `total-wallet-balance`.
     * `balance_for_trading_basis=available` uses `available-balance`.
     * `allow_other_positions=true` forces `available-balance` regardless
     * of the basis: capital locked in the user's own positions must never
     * be counted when sizing Kraite's, or the bot over-commits a wallet
     * it doesn't fully own.
     * Cold starts fall back to the persisted absolute `margin`.
     */
    public function balanceForTrading(): string
    {
        $key = $this->balanceForTradingSnapshotKey();

        $snapshot = ApiSnapshot::getFrom($this, 'account-balance');

        $value = $snapshot[$key] ?? null;

        if ($value === null || $value === '') {
            return (string) ($this->margin ?? '0');
        }

        return (string) $value;
    }

    public function balanceForTradingSnapshotKey(): string
    {
        if ($this->allow_other_positions) {
            return 'available-balance';
        }

        return $this->balance_for_trading_basis === 'available'
            ? 'available-balance'
            : 'total-wallet-balance';
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
        return AccountFactory::new();
    }
}
