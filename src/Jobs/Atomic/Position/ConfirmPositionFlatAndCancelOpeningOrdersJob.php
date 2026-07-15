<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Position;

use Kraite\Core\Abstracts\BaseApiableJob;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Enums\PositionPresence;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\PositionSafety;
use Kraite\Core\Support\PositionSnapshot;
use UnexpectedValueException;

final class ConfirmPositionFlatAndCancelOpeningOrdersJob extends BaseApiableJob
{
    public Position $position;

    public function __construct(
        int $positionId,
        public string $source,
    ) {
        $this->position = Position::findOrFail($positionId);
    }

    public function assignExceptionHandler(): void
    {
        $canonical = $this->position->account->apiSystem->canonical;
        $this->exceptionHandler = BaseExceptionHandler::make($canonical)
            ->withAccount($this->position->account);
    }

    public function relatable()
    {
        return $this->position;
    }

    public function computeApiable(): array
    {
        $apiResponse = $this->position->account->apiQueryPositions();
        $snapshot = PositionSnapshot::fromApiResponse($this->position->account, $apiResponse);

        if (! $snapshot->isValid()) {
            throw new UnexpectedValueException(sprintf(
                'Invalid positions response while confirming flat position #%d.',
                $this->position->id,
            ));
        }

        $confirmedFlat = $snapshot->presenceOf($this->position) === PositionPresence::Flat;
        $cancellationDispatched = $confirmedFlat
            && PositionSafety::dispatchOpeningOrderCancellation($this->position, $this->source);

        return [
            'position_id' => $this->position->id,
            'source' => $this->source,
            'confirmed_flat' => $confirmedFlat,
            'opening_orders_cancel_dispatched' => $cancellationDispatched,
        ];
    }
}
