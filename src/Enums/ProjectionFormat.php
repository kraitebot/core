<?php

declare(strict_types=1);

namespace Kraite\Core\Enums;

/**
 * Output format requested from a financial projection call. Lets a
 * single entry point return either a percentage gain or an absolute
 * money delta without exposing two parallel APIs.
 *
 *  - **Percentage** — float, range typically [-100, +∞), e.g. 8.42 = +8.42%.
 *  - **Amount**     — bcmath-safe string, signed quote-currency delta.
 */
enum ProjectionFormat: string
{
    case Percentage = 'percentage';
    case Amount = 'amount';
}
