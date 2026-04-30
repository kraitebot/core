<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Cronjobs;

use Illuminate\Support\Facades\Log;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Support\NotificationService;
use StepDispatcher\Support\BaseCommand;
use Throwable;

/**
 * CheckStaleDataCommand
 *
 * External alert for the Binance mark-price WebSocket daemon. Runs
 * every minute. Does NOT restart anything — the daemon's internal
 * idle watchdog already handles transient socket stalls.
 *
 * Health signal: any enabled exchange_symbol whose mark_price_synced_at
 * is older than the threshold (default 60 seconds) is considered
 * stale. The daemon writes ~1Hz in healthy state, so a one-minute gap
 * is well past the noise floor.
 *
 * Remediation: fires `price_data_stale` Pushover with the affected
 * symbol count + oldest age + a sample of pairs. Operator decides
 * whether to dig into Binance change-notice / IP block / URL
 * deprecation. We deliberately do NOT supervisor-restart the daemon
 * — restart loops without actionable visibility were the root cause
 * of the 2026-04-23 silent outage.
 */
final class CheckStaleDataCommand extends BaseCommand
{
    private const DEFAULT_STALENESS_THRESHOLD_SECONDS = 60;

    private const SAMPLE_SIZE = 5;

    protected $signature = 'kraite:cron-check-stale-data
                            {--max-staleness-seconds= : Threshold above which a symbol is considered stale (default: 60)}
                            {--output : Display command output (silent by default)}';

    protected $description = 'Alert (Pushover) when any enabled exchange_symbol mark_price_synced_at is older than the threshold. No auto-restart.';

    public function handle(): int
    {
        $threshold = (int) ($this->option('max-staleness-seconds')
            ?: self::DEFAULT_STALENESS_THRESHOLD_SECONDS);

        $rows = ExchangeSymbol::query()
            ->notDelisted()
            ->where('is_manually_enabled', true)
            ->whereNotNull('mark_price_synced_at')
            ->where('mark_price_synced_at', '<', now()->subSeconds($threshold))
            ->orderBy('mark_price_synced_at')
            ->get(['id', 'token', 'quote', 'mark_price_synced_at']);

        if ($rows->isEmpty()) {
            $this->verboseInfo("All non-delisted enabled symbols within {$threshold}s — no alert.");

            return self::SUCCESS;
        }

        $oldestSecondsAgo = (int) now()->diffInSeconds($rows->first()->mark_price_synced_at, true);
        $sample = $rows->take(self::SAMPLE_SIZE)
            ->map(fn ($s) => $s->token.$s->quote)
            ->all();
        $ids = $rows->pluck('id')->all();

        $this->verboseWarn(sprintf(
            'STALE: %d symbols, oldest %ds ago. Sample: %s',
            $rows->count(),
            $oldestSecondsAgo,
            implode(', ', $sample),
        ));

        try {
            NotificationService::send(
                user: Kraite::admin(),
                canonical: 'price_data_stale',
                referenceData: [
                    'stale_count' => $rows->count(),
                    'oldest_age_seconds' => $oldestSecondsAgo,
                    'sample_pairs' => $sample,
                    'exchange_symbol_ids' => $ids,
                ],
                cacheKeys: ['exchange_symbol_ids' => implode(',', $ids)],
            );
        } catch (Throwable $e) {
            Log::channel('jobs')->error(
                '[CHECK-STALE-DATA] notification dispatch failed',
                ['error' => $e->getMessage()]
            );

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
