<?php

declare(strict_types=1);

namespace Kraite\Core\Support;

/**
 * Redacts credential-bearing HTTP headers before they are persisted to
 * `api_request_logs.http_headers_sent`.
 *
 * The exchange auth headers (`ACCESS-KEY`, `ACCESS-PASSPHRASE`,
 * `X-MBX-APIKEY`, `KC-API-KEY`, etc.) are full credentials in plaintext
 * and were leaking into every log row in production. Layer A (Eloquent
 * `$hidden`) blocks the leak via model serialization; this is Layer B,
 * blocking the leak via the recorded request headers.
 *
 * Timestamp headers (`*-TIMESTAMP`) and the `Authorization` value
 * itself are NOT secrets in the same way as keys, but `Authorization`
 * typically carries a bearer token — also redacted.
 *
 * Non-sensitive headers (`Content-Type`, `Accept`, `*-TIMESTAMP`)
 * pass through unchanged so log rows remain useful for debugging
 * request shapes.
 */
final class HeaderSanitizer
{
    public const REDACTION_PLACEHOLDER = '***REDACTED***';

    /**
     * Header names matched case-insensitively. Anything in this list is
     * replaced with the redaction placeholder regardless of value.
     *
     * @var list<string>
     */
    private const SENSITIVE_HEADERS = [
        // Bitget
        'ACCESS-KEY',
        'ACCESS-SIGN',
        'ACCESS-PASSPHRASE',
        // Binance
        'X-MBX-APIKEY',
        // KuCoin
        'KC-API-KEY',
        'KC-API-SIGN',
        'KC-API-PASSPHRASE',
        // Bybit
        'X-BAPI-API-KEY',
        'X-BAPI-SIGN',
        // Kraken
        'API-KEY',
        'API-SIGN',
        // Generic / OAuth / proprietary
        'AUTHORIZATION',
        'X-API-KEY',
    ];

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, mixed>
     */
    public static function sanitize(array $headers): array
    {
        $sensitiveLookup = array_flip(array_map(
            static fn (string $name): string => mb_strtoupper($name),
            self::SENSITIVE_HEADERS,
        ));

        $sanitized = [];
        foreach ($headers as $name => $value) {
            $isSensitive = isset($sensitiveLookup[mb_strtoupper((string) $name)]);
            $sanitized[$name] = $isSensitive ? self::REDACTION_PLACEHOLDER : $value;
        }

        return $sanitized;
    }
}
