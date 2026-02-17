<?php

declare(strict_types=1);

namespace Kraite\Core\Support;

use Carbon\Carbon;

/**
 * Centralized value comparison for detecting semantic equality.
 *
 * Handles type coercion for numeric values and JSON structures,
 * while preserving strict comparison for booleans and strings.
 *
 * Used by ModelLogObserver to avoid false positive change logs.
 */
final class ValueNormalizer
{
    /**
     * Check if two values are semantically equal.
     *
     * Normalizes numeric values and JSON structures for comparison,
     * while keeping boolean and string comparisons strict.
     *
     * @param  mixed  $oldValue  The original value (raw from database)
     * @param  mixed  $newValue  The new value (raw from database)
     * @return bool True if values are semantically equal
     */
    public static function areEqual(mixed $oldValue, mixed $newValue): bool
    {
        // Exact match? Done.
        if ($oldValue === $newValue) {
            return true;
        }

        // Both null? Equal
        if ($oldValue === null && $newValue === null) {
            return true;
        }

        // One null, one not? Different
        if ($oldValue === null || $newValue === null) {
            return false;
        }

        // STRATEGY 1: Boolean/Numeric coercion
        // Handles: 0 vs false, 1 vs true, "0" vs false, "" vs false
        // Only apply if BOTH values are boolean-like (prevents 5 == true)
        if (self::isBooleanLike($oldValue) && self::isBooleanLike($newValue)) {
            return self::toBooleanValue($oldValue) === self::toBooleanValue($newValue);
        }

        // STRATEGY 2: Both numeric? Compare as floats
        // Handles: "5.00000000" vs 5, "0.00001000" vs 0.00001
        if (is_numeric($oldValue) && is_numeric($newValue)) {
            return (float) $oldValue === (float) $newValue;
        }

        // STRATEGY 3: Both JSON-like? Normalize and compare
        // Handles: {"a":1,"b":2} vs {"b":2,"a":1}
        if (self::isJsonLike($oldValue) && self::isJsonLike($newValue)) {
            return self::normalizeJson($oldValue) === self::normalizeJson($newValue);
        }

        // STRATEGY 4: Both Carbon instances? Compare timestamps
        if ($oldValue instanceof Carbon && $newValue instanceof Carbon) {
            return $oldValue->equalTo($newValue);
        }

        // Fallback: Strict comparison (different types or non-comparable)
        return false;
    }

    /**
     * Check if a value is JSON-like (array or valid JSON string).
     */
    private static function isJsonLike(mixed $value): bool
    {
        if (is_array($value)) {
            return true;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if (str_starts_with(haystack: $trimmed, needle: '{') || str_starts_with(haystack: $trimmed, needle: '[')) {
                json_decode($value);

                return json_last_error() === JSON_ERROR_NONE;
            }
        }

        return false;
    }

    /**
     * Normalize JSON value to sorted array for comparison.
     */
    private static function normalizeJson(mixed $value): array
    {
        $array = is_array($value) ? $value : json_decode($value, associative: true);

        return self::sortArrayRecursively($array);
    }

    /**
     * Recursively sort array by keys for deterministic comparison.
     */
    private static function sortArrayRecursively(array $array): array
    {
        ksort($array);

        foreach ($array as $key => $value) {
            if (!(is_array($value))) { continue; }

$array[$key] = self::sortArrayRecursively($value);
        }

        return $array;
    }

    /**
     * Check if a value should be treated as boolean-like.
     * Includes actual booleans, 0, 1, "0", "1", and empty string.
     */
    private static function isBooleanLike(mixed $value): bool
    {
        if (is_bool($value)) {
            return true;
        }

        // Integer 0 or 1
        if ($value === 0 || $value === 1) {
            return true;
        }

        // String "0", "1", or empty string
        if ($value === '0' || $value === '1' || $value === '') {
            return true;
        }

        return false;
    }

    /**
     * Convert a value to its boolean representation using PHP's truthiness rules.
     * This matches how Eloquent casts boolean attributes.
     */
    private static function toBooleanValue(mixed $value): bool
    {
        // Direct boolean
        if (is_bool($value)) {
            return $value;
        }

        // Integer or numeric string: 0 = false, 1 = true
        if (is_numeric($value)) {
            return (int) $value !== 0;
        }

        // String: empty = false, non-empty = true
        if (is_string($value)) {
            return $value !== '';
        }

        // Null = false
        if ($value === null) {
            return false;
        }

        // Fallback: use PHP's truthiness
        return (bool) $value;
    }
}
