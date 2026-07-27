<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\CoinmarketCap;

use Kraite\Core\Abstracts\BaseDataMapper;
use Kraite\Core\Support\ApiDataMappers\CoinmarketCap\ApiRequests\MapsSearchSymbolByToken;
use Kraite\Core\Support\ApiDataMappers\CoinmarketCap\ApiRequests\MapsSymbolMapByIds;
use Kraite\Core\Support\ApiDataMappers\CoinmarketCap\ApiRequests\MapsSyncMarketData;

final class CoinmarketCapDataMapper extends BaseDataMapper
{
    use MapsSearchSymbolByToken;
    use MapsSymbolMapByIds;
    use MapsSyncMarketData;
}
