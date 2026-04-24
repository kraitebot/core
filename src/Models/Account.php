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
