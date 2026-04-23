<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Support\Math;

/**
 * Try running a callback multiple times with optional delays and failure callback.
 *
 * @param  callable  $callback  Main logic to run.
 * @param  int  $maxRetries  Max number of attempts.
 * @param  int|array  $delays  Delay(s) in seconds between retries.
 * @param  callable|null  $onFailure  Callback to run on final failure (receives Throwable).
 * @return mixed
 *
 * @throws Throwable The last exception after all retries fail.
 */
function try_times(callable $callback, int $maxRetries = 3, int|array $delays = [2, 4, 8], ?callable $onFailure = null)
{
    $attempt = 0;

    do {
        try {
            return $callback();
        } catch (Throwable $e) {
            $attempt++;
            if ($attempt >= $maxRetries) {
                if ($onFailure) {
                    $onFailure($e);
                }
                throw $e;
            }

            $delay = is_array($delays)
                ? ($delays[$attempt - 1] ?? end($delays))
                : $delays;

            sleep($delay);
        }
    } while ($attempt < $maxRetries);
}

function returnLadderedValue(array $values, int $index)
{
    return $values[min($index, count($values) - 1)];
}

function summarize_model_attributes(Model $model, array $only = []): string
{
    $attributes = $model->getAttributes();

    if (! empty($only)) {
        $attributes = array_intersect_key($attributes, array_flip($only));
    }

    $parts = [];

    foreach ($attributes as $key => $value) {
        if (! (is_scalar($value) || $value === null)) {
            continue;
        }

        $parts[] = "{$key}=".var_export($value, true);
    }

    return implode(separator: ', ', array: $parts);
}

function format_model_attributes(Model $model, array $only = [], array $except = []): array
{
    return collect($model->getAttributes())
        ->when($only, fn ($c) => $c->only($only))
        ->when($except, fn ($c) => $c->except($except))
        ->filter(fn ($value) => ! is_null($value))
        ->mapWithKeys(function ($value, $key) {
            if (! is_string($value)) {
                return [$key => $value];
            }

            // Try first decode
            $decoded = json_decode($value, associative: true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return [$key => $value];
            }

            // If it's an array or a second-level string, decode recursively
            $deepCleaned = deep_clean_json($decoded);

            return [$key => $deepCleaned];
        })
        ->all();
}

function deep_clean_json($value)
{
    if (is_array($value)) {
        return array_map(callback: 'deep_clean_json', array: $value);
    }

    if (is_string($value)) {
        $decoded = json_decode($value, associative: true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return deep_clean_json($decoded);
        }
    }

    return $value;
}

function get_market_order_amount_divider($totalLimitOrders)
{
    return 2 ** ($totalLimitOrders + 1);
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

/**
 * Log a message to a specific file in storage/logs.
 * Useful for debugging specific subsystems like WebSocket connections.
 *
 * @param  string  $filename  The filename to write to (e.g., 'binance-websocket.log')
 * @param  string  $message  The message to log
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
