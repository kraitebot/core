<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Security;

use UnexpectedValueException;

final class ExchangeApiKeyPermissions
{
    public static function supports(string $exchange): bool
    {
        return in_array($exchange, ['binance', 'bitget'], true);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function withdrawalsEnabled(string $exchange, array $payload): bool
    {
        return match ($exchange) {
            'binance' => self::binanceWithdrawalsEnabled($payload),
            'bitget' => self::bitgetWithdrawalsEnabled($payload),
            default => throw new UnexpectedValueException("Withdrawal permissions are not supported for [{$exchange}]."),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function binanceWithdrawalsEnabled(array $payload): bool
    {
        $enabled = $payload['enableWithdrawals'] ?? null;

        if (! is_bool($enabled)) {
            throw new UnexpectedValueException('Binance did not return a valid withdrawal permission.');
        }

        return $enabled;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function bitgetWithdrawalsEnabled(array $payload): bool
    {
        $authorities = data_get($payload, 'data.authorities');

        if (! is_array($authorities) || collect($authorities)->contains(fn (mixed $authority): bool => ! is_string($authority))) {
            throw new UnexpectedValueException('Bitget did not return valid API key permissions.');
        }

        return in_array('wwow', $authorities, true);
    }
}
