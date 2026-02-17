<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns\TradeConfiguration;

use Kraite\Core\Models\TradeConfiguration;

trait HasGetters
{
    public static function getDefault()
    {
        return TradeConfiguration::default()->first();
    }
}
