<?php

declare(strict_types=1);

namespace Kraite\Core\Support;

/**
 * Removes credential material from API request diagnostics without mutating
 * the actual outbound request.
 */
final class ApiLogSanitizer
{
    public const REDACTION_PLACEHOLDER = HeaderSanitizer::REDACTION_PLACEHOLDER;

    /** @var list<string> */
    private const SENSITIVE_KEYS = [
        'secret',
        'api_key',
        'api_secret',
        'signature',
        'sign',
        'passphrase',
        'password',
        'authorization',
        'access_token',
        'refresh_token',
        'client_secret',
    ];

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public static function payload(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                $payload[$key] = self::REDACTION_PLACEHOLDER;

                continue;
            }

            if (is_array($value)) {
                $payload[$key] = self::payload($value);
            }
        }

        return $payload;
    }

    public static function path(string $path): string
    {
        [$endpoint, $query] = array_pad(explode('?', $path, 2), 2, null);

        if ($query === null || $query === '') {
            return $path;
        }

        $parameters = array_map(static function (string $parameter): string {
            [$encodedKey, $value] = array_pad(explode('=', $parameter, 2), 2, null);

            if (! self::isSensitiveKey(urldecode($encodedKey))) {
                return $parameter;
            }

            return $encodedKey.'='.rawurlencode(self::REDACTION_PLACEHOLDER);
        }, explode('&', $query));

        return $endpoint.'?'.implode('&', $parameters);
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalized = mb_strtolower(str_replace(['-', ' '], '_', $key));

        return in_array($normalized, self::SENSITIVE_KEYS, strict: true)
            || $normalized === 'apikey';
    }
}
