<?php

declare(strict_types=1);

namespace Kraite\Core\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Kraite\Core\Database\Factories\AppPushDeviceFactory;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $expo_push_token
 * @property string $token_hash
 * @property string $platform
 * @property string $device_name
 * @property string|null $app_version
 * @property Carbon $last_registered_at
 * @property Carbon|null $disabled_at
 * @property-read User|null $user
 */
final class AppPushDevice extends Model
{
    /** @use HasFactory<AppPushDeviceFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'expo_push_token',
        'token_hash',
        'platform',
        'device_name',
        'app_version',
        'last_registered_at',
        'disabled_at',
    ];

    protected $hidden = [
        'expo_push_token',
        'token_hash',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNotNull('user_id')
            ->whereNull('disabled_at');
    }

    protected static function newFactory(): AppPushDeviceFactory
    {
        return AppPushDeviceFactory::new();
    }

    protected function casts(): array
    {
        return [
            'expo_push_token' => 'encrypted',
            'last_registered_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }
}
