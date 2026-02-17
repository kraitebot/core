<?php

declare(strict_types=1);

namespace Kraite\Core\Indicators\RefreshData;

use Kraite\Core\Abstracts\BaseIndicator;
use Kraite\Core\Contracts\Indicators\DirectionIndicator;

final class EMAIndicator extends BaseIndicator implements DirectionIndicator
{
    public string $endpoint = 'ema';

    public function conclusion(): ?string
    {
        return $this->direction();
    }

    public function direction(): ?string
    {
        return $this->compareTrendFromValues();
    }
}
