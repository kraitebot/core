<?php

declare(strict_types=1);

namespace Kraite\Core\Enums;

enum NotificationSeverity: string
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Info = 'info';

    /**
     * Get the display label for this severity level.
     */
    public function label(): string
    {
        return match ($this) {
            self::Critical => 'CRITICAL',
            self::High => 'HIGH',
            self::Medium => 'MEDIUM',
            self::Info => 'INFO',
        };
    }

    /**
     * Get the emoji icon for this severity level.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Critical => '🔴',
            self::High => '🟡',
            self::Medium => '🟢',
            self::Info => '🔵',
        };
    }

    /**
     * Get the color code for this severity level (for email templates).
     */
    public function color(): string
    {
        return match ($this) {
            self::Critical => '#DC2626', // Red
            self::High => '#F59E0B', // Amber
            self::Medium => '#10B981', // Green
            self::Info => '#3B82F6', // Blue
        };
    }

    /**
     * Get the background color for badges (lighter shade).
     */
    public function backgroundColor(): string
    {
        return match ($this) {
            self::Critical => '#FEE2E2', // Light red
            self::High => '#FEF3C7', // Light amber
            self::Medium => '#D1FAE5', // Light green
            self::Info => '#DBEAFE', // Light blue
        };
    }
}
