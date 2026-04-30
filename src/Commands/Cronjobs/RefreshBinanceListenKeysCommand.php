<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Cronjobs;

use Illuminate\Support\Facades\Log;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\BinanceListenKey;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Support\Apis\REST\BinanceApi;
use Kraite\Core\Support\NotificationService;
use Kraite\Core\Support\ValueObjects\ApiCredentials;
use StepDispatcher\Support\BaseCommand;
use Throwable;

/**
 * RefreshBinanceListenKeysCommand
 *
 * Keepalive cron for the Binance user data stream daemon. Each Binance
 * account that has a row in `binance_listen_keys` gets its listenKey
 * refreshed via REST POST /fapi/v1/listenKey.
 *
 * Binance auto-expires listenKeys after 60 minutes without keepalive.
 * Running every minute is well inside that window — even if a single
 * tick fails, the next one re-attempts before expiry. Three consecutive
 * failures on the same account fire `binance_listen_key_keepalive_failed`
 * Pushover so an operator can investigate (revoked API key, network
 * partition, etc.).
 *
 * The daemon is the only writer to `binance_listen_keys` rows during
 * normal operation (it inserts on account-init, deletes on
 * listenKeyExpired). This cron only updates the keepalive metadata —
 * never deletes — so a daemon restart still finds the row and reuses
 * the live key.
 */
final class RefreshBinanceListenKeysCommand extends BaseCommand
{
    private const FAILURE_ALERT_THRESHOLD = 3;

    protected $signature = 'kraite:cron-refresh-binance-listen-keys
                            {--output : Display command output (silent by default)}';

    protected $description = 'Refresh every Binance account listenKey to keep the user-data WebSocket alive past the 60-minute auto-expiry.';

    public function handle(): int
    {
        $rows = BinanceListenKey::query()->get();

        if ($rows->isEmpty()) {
            $this->verboseInfo('No binance_listen_keys rows — nothing to refresh.');

            return self::SUCCESS;
        }

        $kept = 0;
        $rotated = 0;
        $failed = 0;

        foreach ($rows as $row) {
            $account = Account::find($row->account_id);

            if ($account === null) {
                $this->verboseWarn("Skipping row #{$row->id} — account #{$row->account_id} no longer exists.");

                continue;
            }

            try {
                $api = new BinanceApi(new ApiCredentials($account->all_credentials));
                $newKey = $api->refreshListenKey($row->listen_key);

                $isRotated = $newKey !== $row->listen_key;

                $row->update([
                    'listen_key' => $newKey,
                    'last_keep_alive_at' => now(),
                    'last_keep_alive_status' => 'success',
                    'failure_count' => 0,
                    'last_created_at' => $isRotated ? now() : $row->last_created_at,
                ]);

                if ($isRotated) {
                    $rotated++;
                } else {
                    $kept++;
                }
            } catch (Throwable $exception) {
                $failed++;

                $row->update([
                    'last_keep_alive_status' => 'failure',
                    'failure_count' => $row->failure_count + 1,
                ]);

                Log::channel('user-data')->warning('[USER-DATA] listen-key keepalive failed', [
                    'account_id' => $row->account_id,
                    'failure_count' => $row->failure_count + 1,
                    'error' => $exception->getMessage(),
                ]);

                if (($row->failure_count + 1) === self::FAILURE_ALERT_THRESHOLD) {
                    $this->raiseAlert($account, $exception, $row->failure_count + 1);
                }
            }
        }

        $this->verboseInfo(sprintf(
            'listen-key refresh: kept=%d rotated=%d failed=%d total=%d',
            $kept,
            $rotated,
            $failed,
            $rows->count()
        ));

        return self::SUCCESS;
    }

    private function raiseAlert(Account $account, Throwable $exception, int $failureCount): void
    {
        try {
            NotificationService::send(
                user: Kraite::admin(),
                canonical: 'binance_listen_key_keepalive_failed',
                referenceData: [
                    'account_id' => $account->id,
                    'account_name' => $account->name,
                    'failure_count' => $failureCount,
                    'error' => $exception->getMessage(),
                ],
                cacheKeys: ['account_id' => (string) $account->id],
            );
        } catch (Throwable $notificationError) {
            Log::channel('user-data')->error('[USER-DATA] alert dispatch failed', [
                'account_id' => $account->id,
                'error' => $notificationError->getMessage(),
            ]);
        }
    }
}
