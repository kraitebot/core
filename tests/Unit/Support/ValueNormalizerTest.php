<?php

declare(strict_types=1);

use Kraite\Core\Support\ValueNormalizer;

test('identical values are equal', function () {
    expect(ValueNormalizer::areEqual(5, 5))->toBeTrue();
    expect(ValueNormalizer::areEqual('foo', 'foo'))->toBeTrue();
    expect(ValueNormalizer::areEqual(true, true))->toBeTrue();
    expect(ValueNormalizer::areEqual(false, false))->toBeTrue();
});

test('both null values are equal', function () {
    expect(ValueNormalizer::areEqual(null, null))->toBeTrue();
});

test('one null and one non-null are different', function () {
    expect(ValueNormalizer::areEqual(null, 0))->toBeFalse();
    expect(ValueNormalizer::areEqual(0, null))->toBeFalse();
    expect(ValueNormalizer::areEqual(null, ''))->toBeFalse();
    expect(ValueNormalizer::areEqual(null, false))->toBeFalse();
});

test('numeric strings and numbers are equal', function () {
    // The main issue: decimal strings vs numbers
    expect(ValueNormalizer::areEqual('5.00000000', '5'))->toBeTrue();
    expect(ValueNormalizer::areEqual('5.00000000', 5))->toBeTrue();
    expect(ValueNormalizer::areEqual(5.00000000, 5))->toBeTrue();
    expect(ValueNormalizer::areEqual('0.00001000', 0.00001))->toBeTrue();
    expect(ValueNormalizer::areEqual('199.99998000', 199.99998))->toBeTrue();
});

test('different numeric values are different', function () {
    expect(ValueNormalizer::areEqual('5.00000000', '6'))->toBeFalse();
    expect(ValueNormalizer::areEqual(5, 6))->toBeFalse();
    expect(ValueNormalizer::areEqual('0.00001', '0.00002'))->toBeFalse();
});

test('boolean-like values are equal', function () {
    // Boolean-like values (true/1, false/0/'') are treated as equal
    // This matches how Eloquent handles boolean casts from database values
    expect(ValueNormalizer::areEqual(true, 1))->toBeTrue();
    expect(ValueNormalizer::areEqual(false, 0))->toBeTrue();
    expect(ValueNormalizer::areEqual(true, '1'))->toBeTrue();
    expect(ValueNormalizer::areEqual(false, ''))->toBeTrue();
    expect(ValueNormalizer::areEqual(false, '0'))->toBeTrue();
});

test('raw database boolean values are equal', function () {
    // When comparing RAW values (0 vs 0, 1 vs 1)
    expect(ValueNormalizer::areEqual(0, 0))->toBeTrue();
    expect(ValueNormalizer::areEqual(1, 1))->toBeTrue();
    expect(ValueNormalizer::areEqual(0, 1))->toBeFalse();
});

test('string booleans are different from numeric booleans', function () {
    // "true" string vs true boolean are different
    expect(ValueNormalizer::areEqual('true', true))->toBeFalse();
    expect(ValueNormalizer::areEqual('false', false))->toBeFalse();
});

test('json arrays and json strings are equal', function () {
    $array = ['a' => 1, 'b' => 2];
    $json = '{"a":1,"b":2}';

    expect(ValueNormalizer::areEqual($array, $json))->toBeTrue();
    expect(ValueNormalizer::areEqual($json, $array))->toBeTrue();
});

test('json arrays with different key order are equal', function () {
    $json1 = '{"a":1,"b":2}';
    $json2 = '{"b":2,"a":1}';

    expect(ValueNormalizer::areEqual($json1, $json2))->toBeTrue();
});

test('json arrays are compared recursively', function () {
    $json1 = '{"outer":{"a":1,"b":2}}';
    $json2 = '{"outer":{"b":2,"a":1}}';

    expect(ValueNormalizer::areEqual($json1, $json2))->toBeTrue();
});

test('different json values are different', function () {
    $json1 = '{"a":1,"b":2}';
    $json2 = '{"a":1,"b":3}';

    expect(ValueNormalizer::areEqual($json1, $json2))->toBeFalse();
});

test('carbon instances with same time are equal', function () {
    $time1 = \Carbon\Carbon::parse('2025-11-23 20:00:00');
    $time2 = \Carbon\Carbon::parse('2025-11-23 20:00:00');

    expect(ValueNormalizer::areEqual($time1, $time2))->toBeTrue();
});

test('carbon instances with different times are different', function () {
    $time1 = \Carbon\Carbon::parse('2025-11-23 20:00:00');
    $time2 = \Carbon\Carbon::parse('2025-11-23 20:00:01');

    expect(ValueNormalizer::areEqual($time1, $time2))->toBeFalse();
});

test('non-numeric strings are compared strictly', function () {
    expect(ValueNormalizer::areEqual('LONG', 'LONG'))->toBeTrue();
    expect(ValueNormalizer::areEqual('LONG', 'SHORT'))->toBeFalse();
    expect(ValueNormalizer::areEqual('abc', 'ABC'))->toBeFalse(); // Case sensitive
});

test('empty string and zero are equal (both falsy)', function () {
    // Empty string and 0 are both boolean-like falsy values
    expect(ValueNormalizer::areEqual('', 0))->toBeTrue();
    // But null is different from falsy values
    expect(ValueNormalizer::areEqual('', null))->toBeFalse();
    expect(ValueNormalizer::areEqual(0, null))->toBeFalse();
});

test('empty array and null are different', function () {
    expect(ValueNormalizer::areEqual([], null))->toBeFalse();
    expect(ValueNormalizer::areEqual('[]', null))->toBeFalse();
});

test('mixed type comparisons return false', function () {
    // String vs array
    expect(ValueNormalizer::areEqual('not json', []))->toBeFalse();

    // Number vs string (non-numeric)
    expect(ValueNormalizer::areEqual(5, 'five'))->toBeFalse();

    // Array vs number
    expect(ValueNormalizer::areEqual([1, 2], 12))->toBeFalse();
});
