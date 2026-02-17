<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Order\Bitget;

use Kraite\Core\Jobs\Atomic\Order\CorrectModifiedOrderJob as BaseCorrectModifiedOrderJob;

/**
 * CorrectModifiedOrderJob (Atomic) - Bitget
 *
 * Bitget-specific implementation for correcting modified LIMIT orders.
 * Uses base implementation - apiModify() works for regular LIMIT orders on Bitget.
 *
 * This variant file exists for JobProxy resolution, ensuring the correct
 * exchange-specific class is used when dispatching correction jobs.
 *
 * Note: Position-level TP/SL orders (is_algo=true) are filtered out by the
 * base class startOrFail() because they require cancel+recreate, not modification.
 */
final class CorrectModifiedOrderJob extends BaseCorrectModifiedOrderJob
{
    // Uses base implementation
    // Override methods here for Bitget-specific behavior if needed
}
