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
     * Bitget key scopes that cannot move funds off the account. Any scope
     * outside this list (withdrawal, transfer, or anything Bitget adds
     * later) counts as withdrawal-capable — fail closed. Values observed
     * live on /api/v3/account/info: "Unified account trading" reports as
     * `uta_trade`, "Unified account management" as `uta_mgt` (2026-07-20).
     */
    private const BITGET_UNIFIED_SAFE_PERMISSIONS = ['uta_trade', 'uta_mgt'];

    /**
     * Classic keys report an `authorities` list ('wwow' = withdrawals);
     * unified (UTA) keys report a `permissions` scope list on the v3
     * account info. Branch on whichever shape the payload carries.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function bitgetWithdrawalsEnabled(array $payload): bool
    {
        $authorities = data_get($payload, 'data.authorities');

        if (is_array($authorities)) {
            if (array_filter($authorities, fn (mixed $v): bool => ! is_string($v)) !== []) {
                throw new UnexpectedValueException('Bitget did not return valid API key permissions.');
            }

            return in_array('wwow', $authorities, true);
        }

        $permissions = data_get($payload, 'data.permissions');

        if (is_array($permissions) && $permissions !== []
            && array_filter($permissions, fn (mixed $v): bool => ! is_string($v)) === []) {
            return array_diff($permissions, self::BITGET_UNIFIED_SAFE_PERMISSIONS) !== [];
        }

        throw new UnexpectedValueException('Bitget did not return valid API key permissions.');
    }
}
