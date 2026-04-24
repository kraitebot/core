<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Cronjobs;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use StepDispatcher\Support\BaseCommand;

/**
 * WatchPriceStreamCommand
 *
 * External watchdog for the Binance mark-price WebSocket daemon
 * (`kraite:stream-binance-prices`). Runs every minute.
 *
 * The daemon's internal idle watchdog (`BaseWebsocketClient`) catches the
 * normal zombie-socket case by detecting stale reads and forcing a
 * reconnect. This command is the belt-and-suspenders layer that catches
 * what the internal watchdog can't — an unresponsive PHP process (OS
 * stall, segfault, deadlock in the event loop, etc.) where even the
 * internal timer isn't firing.
 *
 * Health signal: `MAX(mark_price_synced_at)` across enabled
 * exchange_symbols. The daemon writes on every received frame (~1Hz in
 * healthy state); anything older than the threshold is a frozen stream.
 *
 * Remediation: `sudo -n supervisorctl restart kraite-ingestion-binance-prices`.
 * Relies on the waygou user having NOPASSWD access to supervisorctl for
 * that specific target — configured on the ingestion VPS outside this
 * codebase.
 *
 * Philosophy: restart first, diagnose after. A frozen price stream
 * degrades S/R gating + selection + backtest-adjacent logic immediately,
 * so we bounce the process before investigation. The restart itself is
 * idempotent (supervisor handles the stop/start cleanly).
 */
final class WatchPriceStreamCommand extends BaseCommand
{
    private const DEFAULT_STALENESS_THRESHOLD_SECONDS = 90;

    private const SUPERVISOR_PROGRAM = 'kraite-ingestion-binance-prices';

    private const RESTART_COOLDOWN_SECONDS = 120;

    private const RESTART_COOLDOWN_CACHE_KEY = 'kraite:price_stream_watchdog:last_restart_at';

    protected $signature = 'kraite:watch-price-stream
                            {--max-staleness-seconds= : Threshold above which the stream is considered frozen (default: 60)}
                            {--dry-run : Report staleness but don\'t restart the daemon}
                            {--output : Display command output (silent by default)}';

    protected $description = 'Monitor Binance mark-price staleness and restart the streaming daemon if frozen.';

    public function handle(): int
    {
        $threshold = (int) ($this->option('max-staleness-seconds')
            ?: self::DEFAULT_STALENESS_THRESHOLD_SECONDS);

        $staleness = $this->latestSyncStalenessSeconds();

        if ($staleness === null) {
            $this->verboseInfo(
                'No enabled exchange_symbols have mark_price_synced_at yet — stream likely still warming up. Nothing to do.'
            );

            return self::SUCCESS;
        }

        if ($staleness <= $threshold) {
            $this->verboseInfo("Price stream healthy — last update {$staleness}s ago (threshold {$threshold}s).");

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->verboseWarn(
                "DRY RUN — stream is {$staleness}s stale (threshold {$threshold}s). Would restart ".self::SUPERVISOR_PROGRAM.'.'
            );

            return self::SUCCESS;
        }

        // Cooldown gate. Without this, the watchdog restarts the daemon on
        // every tick while it's still booting — the DB timestamp reflects
        // pre-restart state for the first few seconds of the new process's
        // life and the staleness check always trips until first-write lands.
        // The cooldown also dampens a pathological loop where restarts
        // can't heal the stream (e.g. Binance IP ban); we give each restart
        // two minutes to prove itself before trying again.
        $lastRestartAt = (int) (Cache::get(self::RESTART_COOLDOWN_CACHE_KEY) ?? 0);
        $secondsSinceLastRestart = time() - $lastRestartAt;

        if ($lastRestartAt > 0 && $secondsSinceLastRestart < self::RESTART_COOLDOWN_SECONDS) {
            $this->verboseInfo(sprintf(
                'Stream is %ds stale but daemon was restarted %ds ago — holding off until cooldown (%ds) elapses.',
                $staleness,
                $secondsSinceLastRestart,
                self::RESTART_COOLDOWN_SECONDS
            ));

            return self::SUCCESS;
        }

        Log::channel('jobs')->error(
            '[PRICE-STREAM-WATCHDOG] Stream frozen — restarting daemon',
            ['seconds_stale' => $staleness, 'threshold_seconds' => $threshold]
        );

        $this->verboseWarn(
            "Price stream frozen — last update {$staleness}s ago (threshold {$threshold}s). Restarting daemon."
        );

        $outcome = $this->restartDaemon();

        if ($outcome['exit_code'] !== 0) {
            Log::channel('jobs')->error(
                '[PRICE-STREAM-WATCHDOG] supervisorctl restart failed',
                ['exit_code' => $outcome['exit_code'], 'output' => $outcome['output']]
            );

            $this->verboseWarn(
                "supervisorctl restart failed with code {$outcome['exit_code']}: {$outcome['output']}"
            );

            return self::FAILURE;
        }

        Cache::put(self::RESTART_COOLDOWN_CACHE_KEY, time(), self::RESTART_COOLDOWN_SECONDS * 2);

        Log::channel('jobs')->info(
            '[PRICE-STREAM-WATCHDOG] Daemon restarted',
            ['output' => $outcome['output']]
        );

        $this->verboseInfo("Daemon restart issued ({$outcome['output']}).");

        return self::SUCCESS;
    }

    private function latestSyncStalenessSeconds(): ?int
    {
        /** @var int|null $seconds */
        $seconds = DB::table('exchange_symbols')
            ->where('is_manually_enabled', true)
            ->whereNotNull('mark_price_synced_at')
            ->selectRaw('TIMESTAMPDIFF(SECOND, MAX(mark_price_synced_at), NOW()) AS seconds_stale')
            ->value('seconds_stale');

        return $seconds === null ? null : (int) $seconds;
    }

    /**
     * @return array{exit_code:int, output:string}
     */
    private function restartDaemon(): array
    {
        $programName = escapeshellarg(self::SUPERVISOR_PROGRAM);
        $command = "sudo -n supervisorctl restart {$programName} 2>&1";

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        return [
            'exit_code' => $exitCode,
            'output' => implode(' | ', $output),
        ];
    }
}
