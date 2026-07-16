<?php

declare(strict_types=1);

namespace Kraite\Core\Support;

use Illuminate\Support\Facades\File;
use JsonException;
use Kraite\Core\Exceptions\SystemFrozenException;
use RuntimeException;

final class FreezeMode
{
    public static function markerPath(): string
    {
        $configuredPath = config('kraite.freeze.marker_path');

        return is_string($configuredPath) && $configuredPath !== ''
            ? $configuredPath
            : storage_path(app()->environment('testing') ? 'framework/testing/kraite-frozen' : 'framework/kraite-frozen');
    }

    /** @phpstan-impure */
    public static function isActive(): bool
    {
        return File::exists(self::markerPath());
    }

    /** @throws JsonException */
    public static function activate(): void
    {
        if (self::isActive()) {
            return;
        }

        $path = self::markerPath();
        File::ensureDirectoryExists(dirname($path));

        $payload = json_encode([
            'frozen_at' => now()->toIso8601String(),
            'hostname' => gethostname() ?: 'unknown',
            'pid' => getmypid(),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        $handle = @fopen($path, 'x');

        if ($handle === false) {
            if (self::isActive()) {
                return;
            }

            throw new RuntimeException("Unable to create freeze marker at {$path}.");
        }

        try {
            if (fwrite($handle, $payload.PHP_EOL) === false) {
                throw new RuntimeException("Unable to write freeze marker at {$path}.");
            }
        } finally {
            fclose($handle);
        }

        if (! self::isActive()) {
            throw new RuntimeException("Freeze marker was not persisted at {$path}.");
        }
    }

    public static function deactivate(): void
    {
        if (! self::isActive()) {
            return;
        }

        if (! File::delete(self::markerPath()) || self::isActive()) {
            throw new RuntimeException('Unable to remove the freeze marker. System remains frozen.');
        }
    }

    public static function assertExternalTrafficAllowed(string $operation = 'External traffic'): void
    {
        if (! self::isActive()) {
            return;
        }

        throw new SystemFrozenException("{$operation} blocked: Kraite is frozen.");
    }
}
