<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Taapi;

use Kraite\Core\Support\ApiDataMappers\Taapi\ApiRequests\MapsGroupedQueryIndicators;
use Kraite\Core\Support\ApiDataMappers\Taapi\ApiRequests\MapsQueryIndicator;

final class TaapiApiDataMapper
{
    use MapsGroupedQueryIndicators;
    use MapsQueryIndicator;

    public function baseWithQuote(#[\SensitiveParameter] string $token, string $quote): string
    {
        return $token.'/'.$quote;
    }
}
