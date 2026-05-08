<?php

declare(strict_types=1);

namespace Kraite\Core\Enums;

/**
 * Forward-projection scenario picked by the operator. Drives which
 * daily-percentage anchor is used when the financial calculator
 * compounds today's wallet forward to the end of a window.
 *
 *  - **Pessimistic** — worst observed daily % in the window.
 *  - **Neutral**     — midpoint between worst and best observed daily %.
 *  - **Optimistic**  — best observed daily % in the window.
 */
enum ProjectionScenario: string
{
    case Pessimistic = 'pessimistic';
    case Neutral = 'neutral';
    case Optimistic = 'optimistic';
}
