<?php

declare(strict_types=1);

namespace Kraite\Core\Abstracts;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Kraite\Core\Concerns\BaseModel\HasConditionalUpdates;
use Kraite\Core\Concerns\BaseModel\LogsApplicationEvents;
use Kraite\Core\Concerns\HasModelCache;
use Kraite\Core\Observers\ModelLogObserver;

#[ObservedBy(ModelLogObserver::class)]
abstract class BaseModel extends Model
{
    use HasConditionalUpdates;
    use HasModelCache;
    use LogsApplicationEvents;
}
