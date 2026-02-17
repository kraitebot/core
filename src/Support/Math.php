<?php

declare(strict_types=1);

namespace Kraite\Core\Support;

use InvalidArgumentException;
use RuntimeException;

use function extension_loaded;

/**
 * Precise decimal comparison helpers built on BCMath.
 *
 * This class normalizes inputs (int|float|string) to safe decimal strings and compares using bccomp().
 * Avoid comparing floats directly. Always go through these helpers to eliminate binary rounding noise.
 *
 * Requirements:
 * - PHP extension "bcmath" must be enabled.
 *
 * Conventions:
 * - All methods accept string|int|float, including strings with commas or scientific notation (e.g., "1e-8").
 * - Scale defaults to DEFAULT_SCALE but can be overridden per call.
 */
final class Math
{
    /**
     * Default number of decimal digits to consider in comparisons.
     */
    public const DEFAULT_SCALE = 16;

    /**
     * Compare two numbers with a given scale using bccomp().
     *
     * Returns:
     * - 1 if $a > $b.
     * - 0 if $a equals $b at the given scale.
     * - -1 if $a < $b.
     *
     * @param  string|int|float  $a  Left operand to compare.
     * @param  string|int|float  $b  Right operand to compare.
     * @param  int|null  $scale  Optional decimal scale; defaults to DEFAULT_SCALE.
     *
     * @example
     * Math::cmp('0.1', '0.10');           // 0.
     * Math::cmp(2.5, '2.49', 4);          // 1.
     * Math::cmp('1.0000', '1.0001', 3);   // 0 (equal at 3 decimals).
     */
    public static function cmp(string|int|float $a, string|int|float $b, ?int $scale = null): int
    {
        self::ensureBcmath();
        $s = self::normalizeScale($scale);
        $as = self::toDecimalString($a, $s);
        $bs = self::toDecimalString($b, $s);

        return bccomp($as, $bs, scale: $s);
    }

    /**
     * Add two decimal numbers with BCMath.
     *
     * @param  string|int|float  $a  Augend.
     * @param  string|int|float  $b  Addend.
     * @param  int|null  $scale  Optional decimal scale; defaults to DEFAULT_SCALE.
     * @return string Sum as a normalized decimal string.
     *
     * @example
     * Math::add('1.2', '3.45');          // "4.65".
     * Math::add(0.1, 0.2, 10);           // "0.3000000000".
     */
    public static function add(string|int|float $a, string|int|float $b, ?int $scale = null): string
    {
        self::ensureBcmath();
        $s = self::normalizeScale($scale);

        return bcadd(self::toDecimalString($a, $s), self::toDecimalString($b, $s), scale: $s);
    }

    /**
     * Divide two decimal numbers with BCMath.
     *
     * Note: Division by zero throws a warning in BCMath; validate divisor if needed.
     *
     * @param  string|int|float  $a  Dividend.
     * @param  string|int|float  $b  Divisor.
     * @param  int|null  $scale  Optional decimal scale; defaults to DEFAULT_SCALE.
     * @return string Quotient as a normalized decimal string.
     *
     * @example
     * Math::div('1', '3', 8);            // "0.33333333".
     * Math::div(10, 4, 4);               // "2.5000".
     */
    public static function div(string|int|float $a, string|int|float $b, ?int $scale = null): string
    {
        self::ensureBcmath();
        $s = self::normalizeScale($scale);

        return bcdiv(self::toDecimalString($a, $s), self::toDecimalString($b, $s), scale: $s);
    }

    /**
     * Raise a decimal number to an integer exponent with BCMath.
     *
     * @param  string|int|float  $a  Base.
     * @param  int|string  $exponent  Integer exponent (string accepted; cast to int).
     * @param  int|null  $scale  Optional decimal scale; defaults to DEFAULT_SCALE.
     * @return string Power result as a normalized decimal string.
     *
     * @example
     * Math::pow('2', 10);                 // "1024".
     * Math::pow('1.01', 3, 6);            // "1.030301".
     */
    public static function pow(string|int|float $a, int|string $exponent, ?int $scale = null): string
    {
        self::ensureBcmath();
        $s = self::normalizeScale($scale);

        return bcpow(self::toDecimalString($a, $s), (string) (int) $exponent, $s);
    }

    /**
     * Subtract two decimal numbers with BCMath.
     *
     * @param  string|int|float  $a  Minuend.
     * @param  string|int|float  $b  Subtrahend.
     * @param  int|null  $scale  Optional decimal scale; defaults to DEFAULT_SCALE.
     * @return string Difference as a normalized decimal string.
     *
     * @example
     * Math::sub('5', '2');                // "3".
     * Math::sub('1.0000', '0.1', 4);      // "0.9000".
     */
    public static function sub(string|int|float $a, string|int|float $b, ?int $scale = null): string
    {
        self::ensureBcmath();
        $s = self::normalizeScale($scale);

        return bcsub(self::toDecimalString($a, $s), self::toDecimalString($b, $s), scale: $s);
    }

    /**
     * Multiply two decimal numbers with BCMath.
     *
     * @param  string|int|float  $a  Multiplicand.
     * @param  string|int|float  $b  Multiplier.
     * @param  int|null  $scale  Optional decimal scale; defaults to DEFAULT_SCALE.
     * @return string Product as a normalized decimal string.
     *
     * @example
     * Math::mul('2.5', '4');              // "10".
     * Math::mul('0.1', '0.2', 10);        // "0.0200000000".
     */
    public static function mul(string|int|float $a, string|int|float $b, ?int $scale = null): string
    {
        self::ensureBcmath();
        $s = self::normalizeScale($scale);

        return bcmul(self::toDecimalString($a, $s), self::toDecimalString($b, $s), scale: $s);
    }

    /**
     * Test strict equality at the given scale.
     *
     * @param  string|int|float  $a  Left operand.
     * @param  string|int|float  $b  Right operand.
     * @param  int|null  $scale  Optional decimal scale; defaults to DEFAULT_SCALE.
     *
     * @example
     * Math::equal('0.1', '0.10');         // true.
     * Math::equal(0.3333333, '1/3', 6);   // false, "1/3" is not valid input; use '0.333333' strings.
     * Math::equal('1.2300', '1.23');      // true.
     */
    public static function equal(string|int|float $a, string|int|float $b, ?int $scale = null): bool
    {
        return self::cmp($a, $b, $scale) === 0;
    }

    /**
     * Test greater-than at the given scale.
     *
     * @param  string|int|float  $a  Left operand.
     * @param  string|int|float  $b  Right operand.
     * @param  int|null  $scale  Optional decimal scale; defaults to DEFAULT_SCALE.
     *
     * @example
     * Math::gt('2.5', '2.49');            // true.
     * Math::gt('2.5000', '2.5001', 3);    // false (equal at 3 decimals).
     */
    public static function gt(string|int|float $a, string|int|float $b, ?int $scale = null): bool
    {
        return self::cmp($a, $b, $scale) === 1;
    }

    /**
     * Test greater-than-or-equal at the given scale.
     *
     * @param  string|int|float  $a  Left operand.
     * @param  string|int|float  $b  Right operand.
     * @param  int|null  $scale  Optional decimal scale; defaults to DEFAULT_SCALE.
     *
     * @example
     * Math::gte('2.5', '2.5');            // true.
     * Math::gte('2.4999', '2.5', 3);      // true (equal at 3 decimals).
     */
    public static function gte(string|int|float $a, string|int|float $b, ?int $scale = null): bool
    {
        $c = self::cmp($a, $b, $scale);

        return $c === 1 || $c === 0;
    }

    /**
     * Test less-than at the given scale.
     *
     * @param  string|int|float  $a  Left operand.
     * @param  string|int|float  $b  Right operand.
     * @param  int|null  $scale  Optional decimal scale; defaults to DEFAULT_SCALE.
     *
     * @example
     * Math::lt('2.49', '2.5');            // true.
     * Math::lt('2.5000', '2.5001', 3);    // false (equal at 3 decimals).
     */
    public static function lt(string|int|float $a, string|int|float $b, ?int $scale = null): bool
    {
        return self::cmp($a, $b, $scale) === -1;
    }

    /**
     * Test less-than-or-equal at the given scale.
     *
     * @param  string|int|float  $a  Left operand.
     * @param  string|int|float  $b  Right operand.
     * @param  int|null  $scale  Optional decimal scale; defaults to DEFAULT_SCALE.
     *
     * @example
     * Math::lte('2.5', '2.5');            // true.
     * Math::lte('2.5001', '2.5', 3);      // true (equal at 3 decimals).
     */
    public static function lte(string|int|float $a, string|int|float $b, ?int $scale = null): bool
    {
        $c = self::cmp($a, $b, $scale);

        return $c === -1 || $c === 0;
    }

    /**
     * Approximate equality with an absolute epsilon tolerance at the given scale.
     *
     * This checks: |a - b| <= epsilon. Use this when values should be "close enough."
     * Epsilon accepts decimal strings or scientific notation like "1e-8." The comparison obeys $scale.
     *
     * @param  string|int|float  $a  Left operand.
     * @param  string|int|float  $b  Right operand.
     * @param  string|int|float  $epsilon  Absolute tolerance; default "0.00000001".
     * @param  int|null  $scale  Optional decimal scale; defaults to DEFAULT_SCALE.
     *
     * @example
     * Math::equalWithin('1.00000001', '1', '0.00000002');   // true.
     * Math::equalWithin(0.3000000001, 0.3, '1e-9', 12);     // true.
     * Math::equalWithin('100', '100.0001', '1e-5');         // true.
     */
    public static function equalWithin(
        string|int|float $a,
        string|int|float $b,
        string|int|float $epsilon = '0.00000001',
        ?int $scale = null
    ): bool {
        self::ensureBcmath();
        $s = self::normalizeScale($scale);
        $as = self::toDecimalString($a, $s);
        $bs = self::toDecimalString($b, $s);
        $eps = self::toDecimalString($epsilon, $s);

        $diff = bcsub($as, $bs, scale: $s);
        if (str_starts_with(haystack: $diff, needle: '-')) {
            $diff = mb_substr($diff, 1);
        }

        return bccomp($diff, $eps, scale: $s) <= 0;
    }

    /**
     * Normalize a mixed numeric input to a clean decimal string for BCMath.
     *
     * Rules:
     * - Trims whitespace, converts commas to dots, and removes redundant zeros.
     * - Accepts scientific notation like "1e-8" and expands it deterministically using strings.
     * - Floats are formatted with $scale digits to prevent scientific notation and binary noise.
     * - Produces "0" for empty or sign-only inputs (e.g., "+", "-").
     *
     * @param  string|int|float  $value  Mixed numeric input.
     * @param  int  $scale  Target decimal scale for normalization.
     *
     * @example
     * Math::toDecimalString('  1,2300 ' , 6);      // "1.23".
     * Math::toDecimalString('1e-3', 8);            // "0.001".
     * Math::toDecimalString(0.1, 10);              // "0.1000000000".
     * Math::toDecimalString('-0.000', 6);          // "0".
     */
    private static function toDecimalString(string|int|float $value, int $scale): string
    {
        // Strings: sanitize and handle scientific notation.
        if (is_string($value)) {
            $v = mb_trim($value);
            if ($v === '' || $v === '+' || $v === '-') {
                return '0';
            }

            $v = str_replace(search: ',', replace: '.', subject: $v);

            // Scientific notation handling (e.g., "1e-8" or "-3.2E+5").
            if (preg_match('/^[\+\-]?\d*\.?\d+[eE][\+\-]?\d+$/', $v) === 1) {
                return self::expandScientific($v, $scale);
            }

            // Validate basic decimal format.
            if (preg_match('/^[\+\-]?\d*(\.\d*)?$/', $v) !== 1) {
                throw new InvalidArgumentException("Invalid decimal string: {$value}.");
            }

            return self::normalizeZero(self::trimZeros($v));
        }

        // Integers: cast directly.
        if (is_int($value)) {
            return (string) $value;
        }

        // Floats: validate and format to fixed scale.
        if (! is_finite($value)) {
            throw new InvalidArgumentException('Float value must be finite.');
        }

        $formatted = number_format($value, $scale, '.', '');

        return self::normalizeZero(self::trimZeros($formatted));
    }

    /**
     * Expand a scientific-notation string to a plain decimal string using string math.
     *
     * @param  string  $s  Scientific notation like "1.23e-4".
     * @param  int  $scale  Target scale for padding/truncation.
     *
     * @example
     * Math::expandScientific('1e-3', 8);         // "0.001".
     * Math::expandScientific('-3.2E+5', 2);      // "-320000".
     * Math::expandScientific('4.560E+2', 4);     // "456".
     */
    private static function expandScientific(string $s, int $scale): string
    {
        // Split sign, mantissa, and exponent.
        $sign = '';
        if ($s[0] === '+' || $s[0] === '-') {
            $sign = $s[0] === '-' ? '-' : '';
            $s = mb_substr($s, 1);
        }

        [$mantissa, $expPart] = preg_split('/[eE]/', $s);
        $exp = (int) $expPart;

        // Split mantissa around dot.
        $pos = mb_strpos($mantissa, '.');
        if ($pos === false) {
            $intPart = $mantissa;
            $fracPart = '';
        } else {
            $intPart = mb_substr($mantissa, 0, length: $pos);
            $fracPart = mb_substr($mantissa, $pos + 1);
        }

        // Remove leading zeros from int part and trailing zeros from frac to stabilize.
        $intPart = mb_ltrim($intPart, '0');
        $fracPart = mb_rtrim($fracPart, '0');

        $digits = $intPart.$fracPart;
        if ($digits === '') {
            return '0';
        }

        $decimals = mb_strlen($fracPart);
        $shift = $exp - $decimals;

        if ($shift >= 0) {
            // Move decimal right.
            $digits .=str_repeat('0', $shift);
            $out = $digits;
        } else {
            // Move decimal left.
            $left = mb_strlen($digits) + $shift; // shift is negative here.
            if ($left <= 0) {
                $out = '0.'.str_repeat('0', -$left).$digits;
            } else {
                $out = mb_substr($digits, 0, length: $left).'.'.mb_substr($digits, $left);
            }
        }

        // Reapply sign and tidy zeros.
        $out = $sign.$out;
        $out = self::trimZeros($out);

        // If there is a fractional part, pad/truncate to given scale for stability.
        if (str_contains(haystack: $out, needle: '.')) {
            // Pad to at least 1 and at most $scale decimals deterministically.
            [$i, $f] = explode('.', $out, limit: 2);
            if ($scale <= 0) {
                $out = $i;
            } else {
                $f = mb_substr(mb_str_pad($f, $scale, '0'), 0, length: $scale);
                $out = mb_rtrim($i.'.'.$f, '.');
                $out = self::trimZeros($out);
            }
        }

        return self::normalizeZero($out);
    }

    /**
     * Normalize representations like "-0" or "+0" to "0".
     *
     * @param  string  $s  Decimal string.
     */
    private static function normalizeZero(string $s): string
    {
        // Remove explicit plus sign for consistency.
        if ($s !== '' && $s[0] === '+') {
            $s = mb_substr($s, 1);
        }

        // If numeric value is zero after trimming, return "0".
        if ($s === '' || $s === '.' || $s === '-.' || $s === '+.' || $s === '0' || $s === '-0' || $s === '+0') {
            return '0';
        }

        // "-0.000" → "0".
        if ($s[0] === '-') {
            $abs = mb_substr($s, 1);
            if ($abs === '0' || $abs === '' || $abs === '.' || preg_match('/^0(\.0+)?$/', $abs) === 1) {
                return '0';
            }
        }

        // "+0.000" → "0".
        if ($s[0] === '+') {
            $abs = mb_substr($s, 1);
            if ($abs === '0' || $abs === '' || $abs === '.' || preg_match('/^0(\.0+)?$/', $abs) === 1) {
                return '0';
            }
        }

        return $s;
    }

    /**
     * Trim trailing decimal zeros and the trailing dot when possible.
     *
     * @param  string  $s  Decimal string.
     *
     * @example
     * Math::trimZeros('1.2300');   // "1.23".
     * Math::trimZeros('10.');      // "10".
     * Math::trimZeros('0.000');    // "0".
     */
    private static function trimZeros(string $s): string
    {
        if (str_contains(haystack: $s, needle: '.')) {
            $negative = false;
            if ($s[0] === '-') {
                $negative = true;
                $s = mb_substr($s, 1);
            } elseif ($s[0] === '+') {
                $s = mb_substr($s, 1);
            }

            $s = mb_rtrim(mb_rtrim($s, '0'), '.');

            if ($s === '') {
                return '0';
            }

            return $negative ? '-'.$s : $s;
        }

        return $s;
    }

    /**
     * Ensure BCMath is available.
     */
    private static function ensureBcmath(): void
    {
        if (! extension_loaded('bcmath')) {
            throw new RuntimeException('The BCMath extension is required.');
        }
    }

    /**
     * Normalize provided scale or fallback to default.
     *
     * @param  int|null  $scale  Optional scale.
     */
    private static function normalizeScale(?int $scale): int
    {
        if ($scale === null) {
            return self::DEFAULT_SCALE;
        }

        if ($scale < 0) {
            throw new InvalidArgumentException('Scale must be non-negative.');
        }

        return $scale;
    }
}
