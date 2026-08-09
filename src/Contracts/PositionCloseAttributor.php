<?php

declare(strict_types=1);

namespace Kraite\Core\Contracts;

use Kraite\Core\Enums\PositionCloseAttribution;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\PositionCloseEvidence;

interface PositionCloseAttributor
{
    public function resolve(Position $position, int $flatObservedAtMs): PositionCloseAttribution;

    public function resolveEvidence(Position $position, int $flatObservedAtMs): PositionCloseEvidence;
}
