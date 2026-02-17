<?php

declare(strict_types=1);

namespace Kraite\Core\Models;

use Kraite\Core\Abstracts\BaseModel;
use Kraite\Core\Concerns\Indicator\HasScopes;

final class Indicator extends BaseModel
{
    use HasScopes;

    protected $casts = [
        'is_computed' => 'boolean',
        'is_active' => 'boolean',
        'parameters' => 'array',
    ];

    public function steps()
    {
        return $this->morphMany(Step::class, 'relatable');
    }

    public function apiRequestLogs()
    {
        return $this->morphMany(ApiRequestLog::class, 'relatable');
    }
}
