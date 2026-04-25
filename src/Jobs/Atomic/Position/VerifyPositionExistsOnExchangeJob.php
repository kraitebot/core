<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Position;

use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Jobs\Lifecycles\Position\ClosePositionJob;
use Kraite\Core\Jobs\Lifecycles\Position\SmartReplaceOrdersJob;
use Kraite\Core\Models\ApiSnapshot;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Math;
use Kraite\Core\Support\Proxies\JobProxy;
use StepDispatcher\Models\Step;

/**
 * VerifyPositionExistsOnExchangeJob (Atomic)
 *
 * Reads the account-positions snapshot (fetched by QueryAccountPositionsJob)
 * and checks if the position still exists on the exchange.
 *
 * Decision:
 * - Position GONE → dispatches ClosePositionJob (position was closed externally/manually)
 * - Position EXISTS → dispatches ReplacePositionOrdersJob (orders need recreation)
 */
final class VerifyPositionExistsOnExchangeJob extends BaseQueueableJob
{
    public Position $position;

    public string $triggerStatus;

    public ?string $message;

    public function __construct(int $positionId, string $triggerStatus, ?string $message = null)
    {
        $this->position = Position::findOrFail($positionId);
        $this->triggerStatus = $triggerStatus;
        $this->message = $message;
    }

    public function relatable()
    {
        return $this->position;
    }

    public function compute()
    {
        $position = $this->position;
        $tradingPair = mb_strtoupper(mb_trim($position->parsed_trading_pair));
        $positionExistsOnExchange = false;

        // Read the account-positions snapshot
        $openPositions = ApiSnapshot::getFrom($position->account, 'account-positions');

        if (is_array($openPositions)) {
            foreach ($openPositions as $key => $positionData) {
                $symbol = $positionData['symbol'] ?? $key;
                $symbol = mb_strtoupper(mb_trim($symbol));

                if ($symbol !== $tradingPair) {
                    continue;
                }

                // Get position amount (different exchanges use different field names)
                $positionAmt = (string) ($positionData['positionAmt']
                    ?? $positionData['size']
                    ?? $positionData['qty']
                    ?? $positionData['available']
                    ?? '0');

                $absAmount = Math::lt($positionAmt, '0')
                    ? Math::mul($positionAmt, '-1')
                    : $positionAmt;

                if (Math::gt($absAmount, '0')) {
                    $positionExistsOnExchange = true;
                    break;
                }
            }
        }

        $resolver = JobProxy::with($position->account);

        if (! $positionExistsOnExchange) {
            // Position closed externally — close locally (not cancel, as position was active)
            // Set status to 'closing' immediately so SyncPositionOrdersJob
            // doesn't override it to 'active' before ClosePositionJob runs
            $position->updateToClosing();

            Step::create([
                'class' => $resolver->resolve(ClosePositionJob::class),
                'queue' => 'positions',
                'arguments' => [
                    'positionId' => $position->id,
                    'message' => $this->message ?? "Position closed externally ({$this->triggerStatus})",
                ],
            ]);
        } else {
            // Position still exists — smart replace only missing orders
            Step::create([
                'class' => $resolver->resolve(SmartReplaceOrdersJob::class),
                'queue' => 'positions',
                'arguments' => [
                    'positionId' => $position->id,
                ],
            ]);
        }

        return [
            'position_id' => $position->id,
            'symbol' => $tradingPair,
            'position_exists_on_exchange' => $positionExistsOnExchange,
            'dispatched' => $positionExistsOnExchange ? 'SmartReplaceOrdersJob' : 'ClosePositionJob',
        ];
    }
}
