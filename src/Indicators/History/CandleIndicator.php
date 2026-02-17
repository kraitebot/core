<?php

declare(strict_types=1);

namespace Kraite\Core\Indicators\History;

use Kraite\Core\Abstracts\BaseIndicator;

final class CandleIndicator extends BaseIndicator
{
    public string $endpoint = 'candle';

    public function conclusion(): ?string
    {
        // History indicators store raw data, not conclusions
        return null;
    }
}
