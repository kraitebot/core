<?php

declare(strict_types=1);

namespace Kraite\Core\Support;

use InvalidArgumentException;
use Kraite\Core\Models\Kraite;
use NotificationChannels\Pushover\PushoverChannel;

final class ProductionMonitorNotifier
{
    public function send(
        string $signal,
        string $issue,
        string $cause,
        string $action,
        bool $executed = false,
        bool $resolved = false,
        bool $critical = false,
    ): bool {
        $signal = mb_trim($signal);

        if ($signal === '' || preg_match('/^[a-z0-9][a-z0-9_:-]*$/', $signal) !== 1) {
            throw new InvalidArgumentException('Production monitor signals must use lowercase letters, numbers, underscores, colons, or hyphens.');
        }

        $effectiveSignal = $resolved && ! str_starts_with($signal, 'recovered:')
            ? "recovered:{$signal}"
            : $signal;

        return NotificationService::send(
            user: Kraite::admin(),
            canonical: 'kraite_production_monitor',
            referenceData: [
                'signal' => $effectiveSignal,
                'issue' => $issue,
                'cause' => $cause,
                'action' => $action,
                'executed' => $executed,
                'resolved' => $resolved,
            ],
            duration: $critical && ! $resolved ? 0 : null,
            cacheKeys: ['signal' => $effectiveSignal],
            channels: [PushoverChannel::class],
        );
    }
}
