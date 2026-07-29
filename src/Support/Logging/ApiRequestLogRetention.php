<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Logging;

/**
 * Decides whether an exchange call's response body is worth storing.
 *
 * Every outbound exchange call writes a row to `api_request_logs`. Measured on
 * production 2026-07-29, a successful call's response body averages 11,554
 * bytes — 92% of the row — while everything a runbook actually queries
 * (method, path, status, duration, hostname) fits in under 100. At 67k calls a
 * day that produced a 3.2 GB table against a 2 GB InnoDB buffer pool, making
 * it the most expensive object in the database by I/O time: 1,175 seconds
 * versus 1,065 for `steps`, on a sixth of the reads. Its pages simply do not
 * fit in memory, so they come off disk and evict trading pages on the way.
 *
 * The rule: a body earns its keep when the call failed, or when it was slow
 * enough to be worth looking at. A fast success keeps its shape — status,
 * timing, path — and drops its body.
 *
 * The request payload was already dropped on success by `BaseApiClient`; this
 * covers what remained.
 */
final class ApiRequestLogRetention
{
    /**
     * Columns this class may clear. Deliberately explicit: everything a
     * diagnostic query or alert runbook reads — `http_response_code`,
     * `duration`, `path`, `hostname`, `error_message` — is absent, and a test
     * pins that absence.
     *
     * @var array<int, string>
     */
    public const CLEARABLE_COLUMNS = [
        'response',
        'http_headers_returned',
        'http_headers_sent',
        'debug_data',
    ];

    /** Above this, a successful call is slow enough to be worth keeping. */
    private const DEFAULT_SLOW_MS = 3000;

    /**
     * Whether to keep the heavy body columns for a call that ended with
     * `$statusCode` after `$durationMs`.
     *
     * A null status means the call never completed normally, which is always
     * worth keeping.
     */
    public static function shouldRetainBody(?int $statusCode, int $durationMs): bool
    {
        if (config()->boolean('kraite.api_request_logs.retain_all_bodies', false)) {
            return true;
        }

        if ($statusCode === null || $statusCode >= 400) {
            return true;
        }

        $slowMs = config()->integer('kraite.api_request_logs.retain_body_above_ms', self::DEFAULT_SLOW_MS);

        return $durationMs >= $slowMs;
    }

    /**
     * Blank the heavy columns in a log-data array unless the call earned them.
     *
     * Returns the array unchanged when the body is worth keeping, so callers
     * can apply this unconditionally at the point the outcome is known.
     *
     * @param  array<string, mixed>  $logData
     * @return array<string, mixed>
     */
    public static function apply(array $logData): array
    {
        $statusCode = isset($logData['http_response_code']) ? (int) $logData['http_response_code'] : null;
        $durationMs = (int) ($logData['duration'] ?? 0);

        if (self::shouldRetainBody($statusCode, $durationMs)) {
            return $logData;
        }

        foreach (self::CLEARABLE_COLUMNS as $column) {
            if (array_key_exists($column, $logData)) {
                $logData[$column] = null;
            }
        }

        return $logData;
    }
}
