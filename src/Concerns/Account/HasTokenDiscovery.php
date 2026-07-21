<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns\Account;

use Illuminate\Support\Collection;
use Kraite\Core\Trading\TokenSelection\TokenSelection;

/**
 * Backward-compatible model surface for legacy consumers.
 *
 * New production code must enter through TokenSelection::forAccount().
 */
trait HasTokenDiscovery
{
    public function assignBestTokenToNewPositions(): string
    {
        return TokenSelection::forAccount($this)->assign();
    }

    public function deleteUnassignedPositionSlots(): int
    {
        return TokenSelection::forAccount($this)->deleteUnassignedPositionSlots();
    }

    /**
     * @param  Collection<int, string>  $tokens
     * @return Collection<int, string>
     */
    public function expandTokensWithMappings(Collection $tokens): Collection
    {
        return TokenSelection::forAccount($this)->expandTokensWithMappings($tokens);
    }
}
