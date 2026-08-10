<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Drift;

use Kraite\Core\Models\Account;
use Kraite\Core\Models\Position;

/**
 * Contract for the drift-comparison surface the spotter cron depends on.
 *
 * Exists so the command can declare its dependency in terms of behaviour,
 * not implementation — and so tests can substitute a canned-report fake
 * without battling the production class's `final` modifier.
 */
interface DriftChecker
{
    public function analyseAccount(Account $account): AccountDriftReport;

    public function isExchangePositionOpen(Account $account, Position $position): bool;
}
