<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Kraite\Core\Models\ExchangeSymbol;

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
        if (!(is_scalar($value) || $value === null)) { continue; }

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
    // Force decimal string with up to 15 decimals — avoids sci-notation
    $stringNumber = number_format((float) $number, 15, '.', '');

    // Remove trailing zeros and possible ending dot
    $stringNumber = mb_rtrim(mb_rtrim($stringNumber, '0'), '.');

    // Normalize -0 to 0
    if ($stringNumber === '-0') {
        $stringNumber = '0';
    }

    return $stringNumber;
}

function api_format_quantity($quantity, ExchangeSymbol $exchangeSymbol): string
{
    $precision = (int) $exchangeSymbol->quantity_precision;
    $s = number_format((float) $quantity, $precision + 8, '.', '');
    $dot = mb_strpos($s, '.');
    if ($dot !== false) {
        $s = mb_substr($s, 0, length: $dot + 1 + $precision);
    }
    $s = mb_rtrim($s, '0');
    $s = mb_rtrim($s, '.');

    return $s === '-0' ? '0' : $s;
}

function api_format_price($price, ExchangeSymbol $exchangeSymbol): string
{
    $precision = (int) $exchangeSymbol->price_precision;
    $tickSize = (string) $exchangeSymbol->tick_size;

    // Convert to fixed decimal string with buffer
    $p = number_format((float) $price, $precision + 8, '.', '');
    $t = number_format((float) $tickSize, $precision + 8, '.', '');

    // Floor to nearest tick: floor(price / tick) * tick
    // Use integer division (scale 0) instead of float cast to avoid precision loss on 8-decimal crypto
    $ratio = bcdiv($p, $t, 0); // Integer division = floor for positive prices
    $floored = bcmul($ratio, $t, $precision + 8);

    // Truncate to price precision
    $dot = mb_strpos($floored, '.');
    if ($dot !== false) {
        $floored = mb_substr($floored, 0, length: $dot + 1 + $precision);
    }

    // Clean trailing zeros
    $floored = mb_rtrim($floored, '0');
    $floored = mb_rtrim($floored, '.');

    return $floored === '-0' ? '0' : $floored;
}

/**
 * Log a message to a specific file in storage/logs.
 * Useful for debugging specific subsystems like WebSocket connections.
 *
 * @param  string  $filename  The filename to write to (e.g., 'binance-websocket.log')
 * @param  string  $message  The message to log
 */
function log_on(string $filename, string $message): void
{
    return; // Disabled to prevent log spam

    $logsPath = storage_path('logs');

    if (! is_dir($logsPath)) {
        mkdir($logsPath, 0o755, true);
    }

    $timestamp = now()->format('Y-m-d H:i:s.u');
    $logFile = "{$logsPath}/{$filename}";
    $logMessage = "[{$timestamp}] {$message}" . PHP_EOL;

    file_put_contents($logFile, $logMessage, flags: FILE_APPEND | LOCK_EX);
}
