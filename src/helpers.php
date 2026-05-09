<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Support\Math;

/**
 * Wipe storage/logs clean of sub-directories and *.log files.
 *
 * Intentionally narrow — only called from the ConcludeSymbolsDirectionCommand
 * and FetchKlinesCommand `--clean` operator paths, which also truncate the
 * step trees and API log tables. Not a production safety net; a dev-mode
 * reset helper.
 */
function cleanLogsFolder(): void
{
    $logsPath = storage_path('logs');

    if (! File::isDirectory($logsPath)) {
        return;
    }

    /** @var array<int, string> $directories */
    $directories = File::directories($logsPath);
    foreach ($directories as $directory) {
        File::deleteDirectory($directory);
    }

    /** @var array<int, string> $logFiles */
    $logFiles = File::glob($logsPath.'/*.log');
    foreach ($logFiles as $logFile) {
        File::delete($logFile);
    }
}

function get_market_order_amount_divider($totalLimitOrders)
{
    // Simple-trade mode (N=0): no martingale ladder ever forms, so the
    // MARKET entry must commit the entire margin × leverage budget.
    // The historic 2^(N+1) curve was tuned for ladder reservations and
    // would otherwise leave half the capital sitting idle for rungs that
    // never get placed.
    $n = (int) $totalLimitOrders;

    if ($n <= 0) {
        return 1;
    }

    return 2 ** ($n + 1);
}

function remove_trailing_zeros($number): string
{
    $scale = 40;
    $normalized = normalize_decimal_for_formatting($number, $scale);

    return truncate_decimal_string($normalized, $scale);
}

function api_format_quantity($quantity, ExchangeSymbol $exchangeSymbol): string
{
    $precision = max(0, (int) $exchangeSymbol->quantity_precision);
    $scale = max(24, $precision + 18);
    $normalized = normalize_decimal_for_formatting($quantity, $scale);

    return truncate_decimal_string($normalized, $precision);
}

function api_format_price($price, ExchangeSymbol $exchangeSymbol): string
{
    $precision = max(0, (int) $exchangeSymbol->price_precision);
    $tickSize = (string) $exchangeSymbol->tick_size;
    $scale = max(24, $precision + 18);

    $normalizedPrice = normalize_decimal_for_formatting($price, $scale);
    $normalizedTickSize = normalize_decimal_for_formatting($tickSize, $scale);

    if (Math::equal($normalizedTickSize, '0', $scale)) {
        return truncate_decimal_string($normalizedPrice, $precision);
    }

    // Floor to nearest tick: floor(price / tick) * tick
    // Integer scale (0) keeps positive-price floor behavior deterministic.
    $ratio = Math::div($normalizedPrice, $normalizedTickSize, 0);
    $floored = Math::mul($ratio, $normalizedTickSize, $scale);

    return truncate_decimal_string($floored, $precision);
}

function normalize_decimal_for_formatting(string|int|float $value, int $scale): string
{
    return Math::add($value, '0', $scale);
}

function truncate_decimal_string(string $value, int $precision): string
{
    $sign = '';
    if (mb_strpos($value, '-') === 0) {
        $sign = '-';
        $value = mb_substr($value, 1);
    }

    $parts = explode('.', $value, 2);
    $integerPart = $parts[0] === '' ? '0' : $parts[0];
    $fractionPart = $parts[1] ?? '';

    if ($precision <= 0 || $fractionPart === '') {
        $truncated = $integerPart;
    } else {
        $truncatedFraction = mb_substr($fractionPart, 0, $precision);
        $truncated = "{$integerPart}.{$truncatedFraction}";
    }

    // Only strip trailing zeros when there is a fractional part. Running
    // rtrim('0') on a pure integer corrupts significant digits — "1310"
    // collapses to "131", silently dividing quantities by 10x. That was
    // the root cause of the TAKE #151 / TAKE #146 / TURTLE #149 ladder-
    // rejection class of bugs: integer market_order_quantity values like
    // "1310" or "460" got mangled before being fed into the rung-notional
    // simulation, producing below-min-notional projections on symbols
    // whose real qty was perfectly valid.
    if (str_contains($truncated, '.')) {
        $truncated = mb_rtrim(mb_rtrim($truncated, '0'), '.');
    }

    if ($truncated === '') {
        $truncated = '0';
    }

    $result = $sign.$truncated;

    return $result === '-0' ? '0' : $result;
}

function log_on(string $filename, string $message): void
{
    return; // Disabled to prevent log spam

    $logsPath = storage_path('logs');

    if (! is_dir($logsPath)) {
        mkdir($logsPath, 0o755, true);
    }

    $timestamp = now()->format('Y-m-d H:i:s.u');
    $logFile = "{$logsPath}/{$filename}";
    $logMessage = "[{$timestamp}] {$message}".PHP_EOL;

    file_put_contents($logFile, $logMessage, flags: FILE_APPEND | LOCK_EX);
}
