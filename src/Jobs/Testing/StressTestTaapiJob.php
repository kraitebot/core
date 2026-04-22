<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Testing;

use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\Account;
use Kraite\Core\Support\ValueObjects\ApiProperties;

/**
 * Isolated throttler stress test job.
 *
 * Fires a single TAAPI request with hardcoded parameters. No DB lookups, no
 * side effects, no downstream writes. Used exclusively by the
 * kraite:test-throttler-stress command to measure how the throttler behaves
 * under a concurrent burst of N identical API calls.
 */
final class StressTestTaapiJob extends BaseApiableJob
{
    public int $iteration;

    public function __construct(int $iteration)
    {
        $this->iteration = $iteration;
    }

    public function assignExceptionHandler(): void
    {
        $this->exceptionHandler = BaseExceptionHandler::make('taapi')
            ->withAccount(Account::admin('taapi'));
    }

    public function computeApiable()
    {
        $properties = ApiProperties::make();
        $properties->set('options.endpoint', 'candles');
        $properties->set('options.exchange', 'binancefutures');
        $properties->set('options.symbol', 'BTC/USDT');
        $properties->set('options.interval', '1h');

        Account::admin('taapi')->withApi()->getIndicatorValues($properties);

        return ['iteration' => $this->iteration];
    }
}
