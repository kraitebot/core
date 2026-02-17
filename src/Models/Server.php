<?php

declare(strict_types=1);

namespace Kraite\Core\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Kraite\Core\Abstracts\BaseModel;

/**
 * @property int $id
 * @property string $hostname
 * @property string|null $ip_address
 * @property bool $is_apiable
 * @property bool $needs_whitelisting
 * @property string|null $own_queue_name
 * @property string|null $description
 * @property string $type
 * @property string|null $secret
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class Server extends BaseModel
{
    use HasFactory;

    protected $table = 'servers';

    protected $casts = [
        'is_apiable' => 'boolean',
        'needs_whitelisting' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function accounts()
    {
        return $this->hasMany(Account::class);
    }
}
