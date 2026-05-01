<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Recovery;

/**
 * RecoveryReport
 *
 * Aggregates the outcome of a single recovery run across accounts. Holds
 * structured counters + per-line console output so the command can render
 * a clean human-readable summary at the end without re-deriving anything.
 *
 * Mutated incrementally by the recoverers; rendered once by the command.
 */
final class RecoveryReport
{
    public int $accountsChecked = 0;

    public int $accountsOk = 0;

    public int $accountsSkipped = 0;

    public int $positionsCreated = 0;

    public int $positionsUpdated = 0;

    public int $positionsSkipped = 0;

    public int $ordersCreated = 0;

    public int $ordersSkipped = 0;

    /** @var array<int, string> Console output lines, accumulated in order. */
    public array $lines = [];

    /** @var array<int, string> Soft warnings (skipped symbols, missing data) — surfaced after summary. */
    public array $warnings = [];

    public bool $dryRun = false;

    public function line(string $text): void
    {
        $this->lines[] = $text;
    }

    public function warning(string $text): void
    {
        $this->warnings[] = $text;
    }
}
