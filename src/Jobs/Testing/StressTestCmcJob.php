<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Testing;

use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\Account;
use Kraite\Core\Support\Proxies\ApiDataMapperProxy;

/**
 * Isolated throttler stress test job for CoinMarketCap.
 *
 * Fires a single CMC "search symbol by token" request with a hardcoded
 * token (BTC). No DB lookups, no side effects. Used exclusively by the
 * kraite:test-throttler-stress command to measure throttler behavior
 * under burst load against CMC's rate limits.
 */
final class StressTestCmcJob extends BaseApiableJob
{
    public int $iteration;

    public function __construct(int $iteration)
    {
        $this->iteration = $iteration;
    }

    public function assignExceptionHandler(): void
    {
        $this->exceptionHandler = BaseExceptionHandler::make('coinmarketcap')
            ->withAccount(Account::admin('coinmarketcap'));
    }

    public function computeApiable()
    {
        $mapper = new ApiDataMapperProxy('coinmarketcap');
        $properties = $mapper->prepareSearchSymbolByTokenProperties('BTC');

        Account::admin('coinmarketcap')->withApi()->getSymbols($properties);

        return ['iteration' => $this->iteration];
    }
}
