<?php

declare(strict_types=1);

namespace Kraite\Core\_Jobs\Lifecycles\ApiSystem;

use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\_Jobs\Models\ExchangeSymbol\CalculateBtcCorrelationJob;
use Kraite\Core\_Jobs\Models\ExchangeSymbol\CalculateBtcElasticityJob;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use StepDispatcher\Models\Step;

/**
 * TriggerCorrelationCalculationsJob
 *
 * Creates correlation and elasticity calculation jobs for all USDT symbols on a specific exchange.
 * Runs after exchange sync is complete (SyncMarketData, SyncLeverageBrackets, CheckPriceSpike).
 */
final class TriggerCorrelationCalculationsJob extends BaseQueueableJob
{
    public int $apiSystemId;

    public function __construct(int $apiSystemId)
    {
        $this->apiSystemId = $apiSystemId;
    }

    public function relatable()
    {
        return ApiSystem::find($this->apiSystemId);
    }

    public function compute()
    {
        $correlationEnabled = config('kraite.correlation.enabled');
        $elasticityEnabled = config('kraite.elasticity.enabled');

        if (! $correlationEnabled && ! $elasticityEnabled) {
            return ['skipped' => true, 'reason' => 'Both correlation and elasticity disabled'];
        }

        $apiSystem = ApiSystem::findOrFail($this->apiSystemId);

        $correlationJobsCount = 0;
        $elasticityJobsCount = 0;

        // Create correlation and elasticity jobs for each USDT symbol on this exchange
        ExchangeSymbol::query()
            ->where('api_system_id', $this->apiSystemId)
            ->each(function ($exchangeSymbol) use (&$correlationJobsCount, &$elasticityJobsCount, $correlationEnabled, $elasticityEnabled): void {
                // Create correlation job if enabled
                if ($correlationEnabled) {
                    Step::query()->create([
                        'class' => CalculateBtcCorrelationJob::class,
                        'block_uuid' => $this->uuid(),
                        'arguments' => [
                            'exchangeSymbolId' => $exchangeSymbol->id,
                        ],
                    ]);
                    $correlationJobsCount++;
                }

                // Create elasticity job if enabled (runs in parallel with correlation)
                if ($elasticityEnabled) {
                    Step::query()->create([
                        'class' => CalculateBtcElasticityJob::class,
                        'block_uuid' => $this->uuid(),
                        'arguments' => [
                            'exchangeSymbolId' => $exchangeSymbol->id,
                        ],
                    ]);
                    $elasticityJobsCount++;
                }
            });

        return [
            'api_system' => $apiSystem->canonical,
            'correlation_jobs_created' => $correlationJobsCount,
            'elasticity_jobs_created' => $elasticityJobsCount,
        ];
    }
}
