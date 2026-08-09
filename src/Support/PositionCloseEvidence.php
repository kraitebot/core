<?php

declare(strict_types=1);

namespace Kraite\Core\Support;

use Kraite\Core\Enums\PositionCloseAttribution;

final readonly class PositionCloseEvidence
{
    public function __construct(
        public PositionCloseAttribution $attribution,
        public ?string $closingPrice = null,
    ) {}
}
