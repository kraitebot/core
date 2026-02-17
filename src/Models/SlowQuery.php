<?php

declare(strict_types=1);

namespace Kraite\Core\Models;

use Kraite\Core\Abstracts\BaseModel;

final class SlowQuery extends BaseModel
{
    protected $casts = [
        'bindings' => 'array',
        'time_ms' => 'integer',
    ];
}
