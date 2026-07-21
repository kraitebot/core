<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Health\Remediation;

use Illuminate\Support\Facades\DB;
use Kraite\Core\Jobs\Lifecycles\Position\ApplyWapJob;
use Kraite\Core\Models\Position;
use StepDispatcher\Models\Step;
use StepDispatcher\Support\Steps;

final class WapRemediator
{
    public function heal(Position $position): bool
    {
        return (bool) Steps::usingPrefix('trading', function () use ($position): bool {
            return DB::transaction(function () use ($position): bool {
                $locked = Position::query()->whereKey($position->id)->lockForUpdate()->first();

                if ($locked === null || $locked->status !== 'active') {
                    return false;
                }

                if (Step::hasLiveWorkflow($locked, ApplyWapJob::class)) {
                    return false;
                }

                Step::create([
                    'class' => ApplyWapJob::class,
                    'queue' => 'priority',
                    'priority' => 'high',
                    'relatable_type' => $locked->getMorphClass(),
                    'relatable_id' => $locked->getKey(),
                    'arguments' => [
                        'positionId' => $locked->id,
                        'message' => 'Drift spotter self-heal: TP quantity under-covers the filled DCA ladder — re-applying WAP',
                    ],
                ]);

                return true;
            });
        });
    }
}
