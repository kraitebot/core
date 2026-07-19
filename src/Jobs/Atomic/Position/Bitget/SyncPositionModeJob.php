<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Position\Bitget;

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Proxies\ApiDataMapperProxy;

/** Align the local Bitget position-mode flag with the selected futures product. */
final class SyncPositionModeJob extends BaseApiableJob
{
    public Position $position;

    public function __construct(int $positionId)
    {
        $this->position = Position::findOrFail($positionId);
    }

    public function assignExceptionHandler(): void
    {
        $this->exceptionHandler = BaseExceptionHandler::make('bitget')
            ->withAccount($this->position->account);
    }

    public function relatable(): Position
    {
        return $this->position;
    }

    public function computeApiable(): array
    {
        $account = $this->position->account;
        $mapper = new ApiDataMapperProxy('bitget');
        $properties = $mapper->prepareQueryPositionModeProperties($this->position);
        $properties->set('account', $account);

        /** @var Response $response */
        $response = $account->withApi()->getSingleAccount($properties);
        $positionMode = $mapper->resolveQueryPositionModeResponse($response, $this->position);
        $previousHedgeMode = $account->isHedgeMode();
        $isHedgeMode = $positionMode === 'hedge_mode';

        if ($previousHedgeMode !== $isHedgeMode) {
            $account->updateSaving(['on_hedge_mode' => $isHedgeMode]);
        }

        return [
            'account_id' => $account->id,
            'position_id' => $this->position->id,
            'previous_mode' => $previousHedgeMode ? 'hedge_mode' : 'one_way_mode',
            'position_mode' => $positionMode,
            'changed' => $previousHedgeMode !== $isHedgeMode,
        ];
    }
}
