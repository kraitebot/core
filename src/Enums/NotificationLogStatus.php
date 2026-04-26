<?php

declare(strict_types=1);

namespace Kraite\Core\Enums;

/**
 * Status values written into `notification_logs.status`.
 *
 * Centralised so the listener (delivery), observer (recovery), webhook
 * controller (Zeptomail callbacks), and model scopes share a single
 * source of truth — previously these were bare string literals scattered
 * across four files with subtle casing inconsistencies (`'soft bounced'`
 * vs `'soft_bounced'` etc.) that could silently produce incorrect audit
 * state on a typo.
 *
 * String values are preserved as-is to maintain backward compatibility
 * with existing `notification_logs` rows already in production.
 */
enum NotificationLogStatus: string
{
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Opened = 'opened';
    case SoftBounced = 'soft bounced';
    case HardBounced = 'hard bounced';

    public function isBounce(): bool
    {
        return $this === self::SoftBounced || $this === self::HardBounced;
    }

    public function isDeliveredOrOpened(): bool
    {
        return $this === self::Delivered || $this === self::Opened;
    }
}
