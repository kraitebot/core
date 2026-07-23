<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Cronjobs;

use Illuminate\Support\Facades\Log;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\BinanceListenKey;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Support\NotificationService;
use StepDispatcher\Support\BaseCommand;
use Throwable;

/**
 * CheckBinanceListenKeysStaleCommand
 *
 * Watchdog over `binance_listen_keys`. For every Binance account that
 * is eligible to be streaming (`api_system=binance, is_active=true,
 * binance_api_key NOT NULL`) we expect:
 *
 *   1. A row in `binance_listen_keys`. The user-data daemon writes one
 *      on `initAccount`. If absent past the grace window, the daemon
 *      hasn't touched this account — could be daemon down, init
 *      failure on this account specifically, or DB write failure.
 *
 *   2. A `last_keep_alive_at` timestamp newer than the threshold. The
 *      keepalive cron (`kraite:cron-refresh-binance-listen-keys`) runs
 *      every minute. A stale timestamp means the cron isn't refreshing
 *      this row — could be cron skipped, REST PUT failure (alert is
 *      already raised at 3 consecutive failures via
 *      `binance_listen_key_keepalive_failed`, but THIS check covers
 *      the case where the cron isn't even running).
 *
 * Either signal fires `binance_listen_key_stale` Pushover with the
 * affected account + reason. Threshold defaults to 30 minutes — well
 * below Binance's 60-minute hard expiry, so we have time to respond
 * before the WS dies.
 *
 * Does NOT auto-remediate — same philosophy as CheckStaleDataCommand:
 * surface to a human, no supervisorctl restart loop. The daemon's own
 * reaper / spawner handles legitimate runtime transitions.
 */
final class CheckBinanceListenKeysStaleCommand extends BaseCommand
{
    private const DEFAULT_STALENESS_THRESHOLD_MINUTES = 30;

    /**
     * Grace window before "missing row" alert fires. A freshly-activated
     * account may take up to one discovery sweep (60s) for the daemon
     * to spawn its slot; we double that to be safe.
     */
    private const MISSING_ROW_GRACE_SECONDS = 120;

    protected $signature = 'kraite:cron-check-binance-listen-keys-stale
                            {--max-staleness-minutes= : Threshold above which a listenKey is considered stale (default: 30)}
                            {--output : Display command output (silent by default)}';

    protected $description = 'Alert (Pushover) when an active Binance account is missing a listenKey row OR the keepalive timestamp is older than the threshold.';

    public function handle(): int
    {
        $thresholdMinutes = (int) ($this->option('max-staleness-minutes')
            ?: self::DEFAULT_STALENESS_THRESHOLD_MINUTES);

        $threshold = now()->subMinutes($thresholdMinutes);
        $missingGrace = now()->subSeconds(self::MISSING_ROW_GRACE_SECONDS);

        $eligibleAccounts = Account::query()
            ->whereHas('apiSystem', fn ($q) => $q->canonical('binance'))
            ->where('is_active', true)
            ->whereNotNull('binance_api_key')
            ->get(['id', 'name', 'created_at', 'updated_at']);

        if ($eligibleAccounts->isEmpty()) {
            $this->verboseInfo('No eligible Binance accounts — no alert.');

            return self::SUCCESS;
        }

        $existingRows = BinanceListenKey::query()
            ->whereIn('account_id', $eligibleAccounts->pluck('id'))
            ->get(['account_id', 'last_keep_alive_at'])
            ->keyBy('account_id');

        $alerts = [];

        foreach ($eligibleAccounts as $account) {
            $row = $existingRows->get($account->id);

            // Missing row only counts as stale if the account itself
            // has been eligible long enough for the daemon to have
            // had a chance to spawn its slot. A just-activated account
            // is NOT a missing-row alert.
            $becameEligibleBefore = $account->updated_at <= $missingGrace;

            if ($row === null && $becameEligibleBefore) {
                $alerts[] = [
                    'account_id' => $account->id,
                    'account_name' => $account->name,
                    'reason' => 'missing_row',
                    'detail' => 'No binance_listen_keys row — daemon has not initialised this account.',
                ];

                continue;
            }

            if ($row === null) {
                continue;
            }

            if ($row->last_keep_alive_at === null || $row->last_keep_alive_at < $threshold) {
                $minutesStale = $row->last_keep_alive_at === null
                    ? null
                    : (int) now()->diffInMinutes($row->last_keep_alive_at, true);

                $alerts[] = [
                    'account_id' => $account->id,
                    'account_name' => $account->name,
                    'reason' => 'stale_keep_alive',
                    'detail' => $minutesStale === null
                        ? 'last_keep_alive_at is NULL — keepalive cron has never refreshed this row.'
                        : "last_keep_alive_at is {$minutesStale}min old (threshold: {$thresholdMinutes}min).",
                ];
            }
        }

        if ($alerts === []) {
            $this->verboseInfo("All {$eligibleAccounts->count()} eligible Binance account(s) have fresh listenKey rows — no alert.");

            return self::SUCCESS;
        }

        foreach ($alerts as $alert) {
            $this->verboseWarn("STALE: account #{$alert['account_id']} ({$alert['account_name']}) — {$alert['reason']}: {$alert['detail']}");

            try {
                NotificationService::send(
                    user: Kraite::admin(),
                    canonical: 'binance_listen_key_stale',
                    referenceData: [
                        'account_id' => $alert['account_id'],
                        'account_name' => $alert['account_name'],
                        'reason' => $alert['reason'],
                        'detail' => $alert['detail'],
                        'threshold_minutes' => $thresholdMinutes,
                    ],
                    cacheKeys: ['account_id' => (string) $alert['account_id']],
                );
            } catch (Throwable $e) {
                Log::channel('jobs')->error(
                    '[CHECK-LISTEN-KEYS-STALE] notification dispatch failed',
                    ['account_id' => $alert['account_id'], 'error' => $e->getMessage()]
                );
            }
        }

        return self::SUCCESS;
    }
}
