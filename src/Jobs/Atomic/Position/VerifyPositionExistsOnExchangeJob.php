<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Position;

use Illuminate\Support\Facades\DB;
use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Contracts\PositionCloseAttributor;
use Kraite\Core\Enums\PositionCloseAttribution;
use Kraite\Core\Enums\PositionPresence;
use Kraite\Core\Jobs\Lifecycles\Position\ClosePositionJob;
use Kraite\Core\Jobs\Lifecycles\Position\PreparePositionReplacementJob;
use Kraite\Core\Jobs\Lifecycles\Position\SmartReplaceOrdersJob;
use Kraite\Core\Models\ApiSnapshot;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Math;
use Kraite\Core\Support\PositionCloseEvidence;
use Kraite\Core\Support\PositionSafety;
use Kraite\Core\Support\PositionSnapshot;
use Kraite\Core\Support\Proxies\JobProxy;
use StepDispatcher\Models\Step;
use UnexpectedValueException;

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

    public function __construct(
        int $positionId,
        string $triggerStatus,
        ?string $message = null,
        public bool $confirmationAttempt = false,
        public bool $manualCloseDetected = false,
        public ?int $flatObservedAtMs = null,
        public ?string $manualClosingPrice = null,
    ) {
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

        $openPositions = ApiSnapshot::getFrom($position->account, 'account-positions');

        if (! is_array($openPositions)) {
            throw new UnexpectedValueException(
                "Trusted account-positions snapshot is missing for position #{$position->id}.",
            );
        }

        $presence = PositionSnapshot::fromValidatedResult($openPositions)->presenceOf($position);

        if ($presence === PositionPresence::Unknown) {
            throw new UnexpectedValueException(
                "Trusted account-positions snapshot is malformed for position #{$position->id}.",
            );
        }

        $positionExistsOnExchange = $presence === PositionPresence::Open;
        $resolver = JobProxy::with($position->account);

        if (! $positionExistsOnExchange && ! $this->confirmationAttempt) {
            $dispatched = $this->scheduleConfirmation($position, $resolver);

            return [
                'position_id' => $position->id,
                'symbol' => $tradingPair,
                'position_exists_on_exchange' => false,
                'confirmation_attempt' => false,
                'dispatched' => $dispatched,
            ];
        }

        $openingCancellationDispatched = ! $positionExistsOnExchange
            && PositionSafety::dispatchOpeningOrderCancellation($position, 'replacement-confirmed-flat');
        $manualCloseEvidence = $this->manualCloseEvidence($position, $positionExistsOnExchange);

        // Lock + dedupe pattern matching OrderObserver dispatch sites.
        // VerifyPositionExistsOnExchangeJob is a competing lifecycle
        // creator for the same position; under concurrent sync /
        // websocket / drift / recovery input, two workflows can be
        // admitted for one position/action without this guard. Lock
        // the row, re-read, dedupe by class, then mutate state and
        // create the step in one transaction.
        $dispatched = DB::transaction(function () use ($manualCloseEvidence, $position, $resolver, $positionExistsOnExchange): string {
            $locked = Position::query()->whereKey($position->id)->lockForUpdate()->firstOrFail();

            if (! $positionExistsOnExchange) {
                $closePositionClass = $resolver->resolve(ClosePositionJob::class);

                // Only persist the manual origin after two independent reads
                // confirm the external reduce-only fill left no exposure.
                if ($manualCloseEvidence->attribution === PositionCloseAttribution::External) {
                    $locked->updateIfNotSet('manually_closed_at', now());

                    if (Math::isPositive($manualCloseEvidence->closingPrice)) {
                        $locked->updateIfNotSet('closing_price', $manualCloseEvidence->closingPrice);
                    }
                }

                if (Step::hasLiveWorkflow($locked, $closePositionClass)) {
                    return 'ClosePositionJob (already pending)';
                }

                // Position closed externally — close locally (not
                // cancel, as position was active). Set status to
                // 'closing' immediately so SyncPositionOrdersJob
                // doesn't override it to 'active' before
                // ClosePositionJob runs. Use the locked row.
                $locked->updateToClosing();

                Step::create([
                    'class' => $closePositionClass,
                    'queue' => 'positions',
                    'priority' => $this->step->priority,
                    'relatable_type' => $locked->getMorphClass(),
                    'relatable_id' => $locked->getKey(),
                    'arguments' => [
                        'positionId' => $locked->id,
                        'message' => $this->message ?? "Position closed externally ({$this->triggerStatus})",
                        'positionConfirmedFlat' => true,
                    ],
                ]);

                return 'ClosePositionJob';
            }

            $smartReplaceClass = $resolver->resolve(SmartReplaceOrdersJob::class);

            if (Step::hasLiveWorkflow($locked, $smartReplaceClass)) {
                return 'SmartReplaceOrdersJob (already pending)';
            }

            Step::create([
                'class' => $smartReplaceClass,
                'queue' => 'positions',
                'priority' => $this->step->priority,
                'relatable_type' => $locked->getMorphClass(),
                'relatable_id' => $locked->getKey(),
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
            'confirmation_attempt' => $this->confirmationAttempt,
            'opening_orders_cancel_dispatched' => $openingCancellationDispatched,
            'dispatched' => $dispatched,
        ];
    }

    private function scheduleConfirmation(Position $position, JobProxy $resolver): string
    {
        return DB::transaction(function () use ($position, $resolver): string {
            $locked = Position::query()->whereKey($position->id)->lockForUpdate()->firstOrFail();
            $confirmationClass = $resolver->resolve(PreparePositionReplacementJob::class);

            $alreadyScheduled = Step::query()
                ->forRelatable($locked)
                ->forClasses($confirmationClass)
                ->inProgress()
                ->whereJsonContains('arguments->confirmationAttempt', true)
                ->exists();

            if ($alreadyScheduled) {
                return 'PreparePositionReplacementJob confirmation (already pending)';
            }

            Step::create([
                'class' => $confirmationClass,
                'queue' => 'priority',
                'priority' => 'high',
                'relatable_type' => $locked->getMorphClass(),
                'relatable_id' => $locked->getKey(),
                'arguments' => [
                    'positionId' => $locked->id,
                    'triggerStatus' => $this->triggerStatus,
                    'message' => $this->message,
                    'confirmationAttempt' => true,
                    'manualCloseDetected' => $this->manualCloseDetected,
                    'flatObservedAtMs' => $this->flatObservedAtMs ?? now()->getTimestampMs(),
                    'manualClosingPrice' => $this->manualClosingPrice,
                ],
                'dispatch_after' => now()->addSeconds(
                    (int) config('kraite.position_safety.flat_confirmation_delay_seconds', 20),
                ),
            ]);

            return 'PreparePositionReplacementJob confirmation';
        });
    }

    private function manualCloseEvidence(Position $position, bool $positionExistsOnExchange): PositionCloseEvidence
    {
        if ($positionExistsOnExchange) {
            return new PositionCloseEvidence(PositionCloseAttribution::Unknown);
        }

        if ($this->manualCloseDetected) {
            return new PositionCloseEvidence(
                PositionCloseAttribution::External,
                $this->manualClosingPrice,
            );
        }

        return app(PositionCloseAttributor::class)->resolveEvidence(
            $position,
            $this->flatObservedAtMs ?? now()->getTimestampMs(),
        );
    }
}
