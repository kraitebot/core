<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Order\Binance;

use Kraite\Core\Jobs\Atomic\Order\CorrectModifiedOrderJob as BaseCorrectModifiedOrderJob;

/**
 * CorrectModifiedOrderJob (Atomic) - Binance
 *
 * Binance-specific implementation for correcting modified LIMIT orders.
 * Currently identical to base implementation but allows for exchange-specific
 * overrides in the future (e.g., different API responses, rate limits).
 *
 * Binance Futures supports order modification via PUT /fapi/v1/order
 * which is handled by apiModify() in the base class.
 */
final class CorrectModifiedOrderJob extends BaseCorrectModifiedOrderJob
{
    // Uses base implementation
    // Override methods here for Binance-specific behavior if needed
}
