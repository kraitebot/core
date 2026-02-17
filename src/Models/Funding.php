<?php

declare(strict_types=1);

namespace Kraite\Core\Models;

use Kraite\Core\Abstracts\BaseModel;

final class Funding extends BaseModel
{
    protected $casts = [
        'date_value' => 'datetime',
    ];
}
