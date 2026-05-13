<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Position;

use Illuminate\Support\Facades\DB;
use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Jobs\Lifecycles\Position\ClosePositionJob;
use Kraite\Core\Jobs\Lifecycles\Position\SmartReplaceOrdersJob;
use Kraite\Core\Models\ApiSnapshot;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Math;
use Kraite\Core\Support\Proxies\JobProxy;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Dispatched;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;

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

        // Lock + dedupe pattern matching OrderObserver dispatch sites.
        // VerifyPositionExistsOnExchangeJob is a competing lifecycle
        // creator for the same position; under concurrent sync /
        // websocket / drift / recovery input, two workflows can be
        // admitted for one position/action without this guard. Lock
        // the row, re-read, dedupe by class, then mutate state and
        // create the step in one transaction.
        $dispatched = DB::transaction(function () use ($position, $resolver, $positionExistsOnExchange): string {
            $locked = Position::query()->whereKey($position->id)->lockForUpdate()->firstOrFail();

            if (! $positionExistsOnExchange) {
                $alreadyPending = Step::query()
                    ->where('class', $resolver->resolve(ClosePositionJob::class))
                    ->whereRaw("CAST(JSON_EXTRACT(arguments, '$.positionId') AS UNSIGNED) = ?", [$position->id])
                    ->whereIn('state', [Pending::class, Dispatched::class, Running::class])
                    ->exists();

                if ($alreadyPending) {
                    return 'ClosePositionJob (already pending)';
                }

                // Position closed externally — close locally (not
                // cancel, as position was active). Set status to
                // 'closing' immediately so SyncPositionOrdersJob
                // doesn't override it to 'active' before
                // ClosePositionJob runs. Use the locked row.
                $locked->updateToClosing();

                Step::create([
                    'class' => $resolver->resolve(ClosePositionJob::class),
                    'queue' => 'positions',
                    'priority' => $this->step->priority,
                    'arguments' => [
                        'positionId' => $locked->id,
                        'message' => $this->message ?? "Position closed externally ({$this->triggerStatus})",
                    ],
                ]);

                return 'ClosePositionJob';
            }

            $alreadyPending = Step::query()
                ->where('class', $resolver->resolve(SmartReplaceOrdersJob::class))
                ->whereRaw("CAST(JSON_EXTRACT(arguments, '$.positionId') AS UNSIGNED) = ?", [$position->id])
                ->whereIn('state', [Pending::class, Dispatched::class, Running::class])
                ->exists();

            if ($alreadyPending) {
                return 'SmartReplaceOrdersJob (already pending)';
            }

            Step::create([
                'class' => $resolver->resolve(SmartReplaceOrdersJob::class),
                'queue' => 'positions',
                'priority' => $this->step->priority,
                'arguments' => [
                    'positionId' => $locked->id,
                ],
            ]);

            return 'SmartReplaceOrdersJob';
        });

        return [
            'position_id' => $position->id,
            'symbol' => $tradingPair,
            'position_exists_on_exchange' => $positionExistsOnExchange,
            'dispatched' => $dispatched,
        ];
    }
}
