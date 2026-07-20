<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Lifecycles\ApiSystem;

use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Jobs\Atomic\ApiSystem\SyncLeverageBracketsJob as AtomicSyncLeverageBracketsJob;
use Kraite\Core\Models\ApiSystem;
use StepDispatcher\Models\Step;

/**
 * SyncLeverageBracketsJob (Lifecycle)
 *
 * Parent lifecycle job that creates child step(s) for syncing leverage brackets.
 * Default implementation creates a single atomic step for batch fetching.
 *
 * This works for exchanges that return all symbols in one API call:
 * - Binance: getLeverageBrackets returns all symbols (requires IP whitelist)
 *
 * Exchanges requiring per-symbol calls (Bybit, KuCoin, BitGet) have overrides
 * that create child steps for each symbol.
 */
final class SyncLeverageBracketsJob extends BaseQueueableJob
{
    public ApiSystem $apiSystem;

    public function __construct(int $apiSystemId)
    {
        $this->apiSystem = ApiSystem::findOrFail($apiSystemId);
    }

    public function relatable(): ApiSystem
    {
        return $this->apiSystem;
    }

    /** @return array{exchange: string, steps_created: int, message: string} */
    public function compute(): array
    {
        $this->buildChildChainOnce(function (string $childBlockUuid): void {
            Step::create([
                'class' => AtomicSyncLeverageBracketsJob::class,
                'queue' => 'cronjobs',
                'arguments' => ['apiSystemId' => $this->apiSystem->id],
                'block_uuid' => $childBlockUuid,
                'index' => 1,
            ]);
        });

        return [
            'exchange' => $this->apiSystem->canonical,
            'steps_created' => 1,
            'message' => 'Created batch leverage brackets sync step',
        ];
    }
}
