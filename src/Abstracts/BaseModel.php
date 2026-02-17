<?php

declare(strict_types=1);

namespace Kraite\Core\Abstracts;

use Illuminate\Database\Eloquent\Model;
use Kraite\Core\Concerns\BaseModel\HasConditionalUpdates;
use Kraite\Core\Concerns\BaseModel\LogsApplicationEvents;
use Kraite\Core\Concerns\HasModelCache;

abstract class BaseModel extends Model
{
    use HasModelCache;
    use HasConditionalUpdates;
    use LogsApplicationEvents;
}
