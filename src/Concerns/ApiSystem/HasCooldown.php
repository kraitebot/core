<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns\ApiSystem;

trait HasCooldown
{
    /**
     * Check if this exchange is currently in cooldown.
     * During cooldown, no new positions should be opened.
     */
    public function inCooldown(): bool
    {
        return $this->cooldown_until !== null && $this->cooldown_until->isFuture();
    }

    /**
     * Activate cooldown for this exchange.
     * Uses a sliding window — always resets the duration on new triggers.
     */
    public function activateCooldown(): void
    {
        $durationMinutes = (int) config('kraite.cooldown_duration_minutes', 30);

        $this->update([
            'cooldown_until' => now()->addMinutes($durationMinutes),
        ]);
    }
}
