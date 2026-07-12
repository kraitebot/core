<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Cronjobs;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use StepDispatcher\Support\BaseCommand;
use Throwable;

/**
 * MonitorNarrateCommand
 *
 * The Haiku documentation layer of the trading money-guard. It NEVER
 * detects and NEVER decides — the deterministic PHP guard (CheckDrifts
 * Scope 3 + 4) already cooled the bot and wrote the incident stub. This
 * command only ENRICHES an open, un-narrated incident file into a
 * readable narrative for the operator / terminal LLM, using the cheapest
 * Anthropic model through the Claude CLI on Bruno's subscription
 * (`claude -p`, no API key).
 *
 * Reliability contract: if the CLI is absent, unauthenticated, or errors,
 * the command leaves the deterministic stub untouched and exits 0. The
 * bot's protection never depends on this running — the cooldown already
 * happened before this command exists to run.
 *
 * Scheduled every 20 minutes. Cheap on the common path: no open incident
 * → reads one marker file → exits.
 */
final class MonitorNarrateCommand extends BaseCommand
{
    protected $signature = 'kraite:monitor-narrate';

    protected $description = 'Enrich an open trading-guard incident file with a Haiku-written narrative (documentation only, never decides).';

    public function handle(): int
    {
        $dir = base_path('monitoring');
        $marker = $dir.'/OPEN-INCIDENT';

        if (! File::exists($marker)) {
            $this->verboseInfo('No open incident — nothing to narrate.');

            return self::SUCCESS;
        }

        $incidentFile = $dir.'/'.trim((string) File::get($marker));
        if (! File::exists($incidentFile)) {
            $this->verboseComment('OPEN-INCIDENT marker points at a missing file — skipping.');

            return self::SUCCESS;
        }

        $incident = (string) File::get($incidentFile);

        // Already narrated → done. Alarm-once, narrate-once.
        if (! str_contains($incident, 'narrated: NO')) {
            $this->verboseInfo('Incident already narrated.');

            return self::SUCCESS;
        }

        $narrative = $this->narrate($incident, $this->recentErrorLog());

        if ($narrative === null) {
            // CLI unavailable / failed — leave the stub, try again next run.
            return self::SUCCESS;
        }

        try {
            $updated = str_replace('narrated: NO', 'narrated: YES', $incident);
            $updated = str_replace(
                "## Narration (Haiku fills this in)\n\n_pending_",
                "## Narration (Haiku)\n\n".$narrative,
                $updated,
            );
            File::put($incidentFile, $updated);
            $this->verboseComment('Incident narrated.');
        } catch (Throwable $e) {
            Log::error('[monitor-narrate] failed to write narrative back', ['message' => $e->getMessage()]);
        }

        return self::SUCCESS;
    }

    /**
     * Run the cheap model with a rigidly prescriptive prompt. Haiku follows
     * steps; it does not reason. Returns the narrative text, or null if the
     * CLI is unavailable / errored / returned nothing.
     */
    private function narrate(string $incident, string $logExcerpt): ?string
    {
        $prompt = <<<PROMPT
        You are a documentation assistant. You do NOT make decisions. Follow these steps EXACTLY and output ONLY the result.

        You are given (1) a trading-system INCIDENT record and (2) a LOG EXCERPT.

        Produce a short markdown report with EXACTLY these four sections, nothing before or after:

        ### What happened
        Write 2-4 plain sentences describing, from the incident's `trigger` and `evidence`, what the system detected. Do not speculate beyond the data.

        ### Related log lines
        List up to 8 verbatim lines from the LOG EXCERPT that mention the same exchange/error/position as the incident. If none match, write "None found in excerpt."

        ### Most likely area to check first
        One sentence naming the single most likely component or code path, based only on the trigger name and matching log lines.

        ### Confidence
        One word: high, medium, or low.

        --- INCIDENT ---
        {$incident}

        --- LOG EXCERPT ---
        {$logExcerpt}
        PROMPT;

        try {
            // Invoke as an argv ARRAY (no shell): a binary path containing
            // spaces, or an arg with spaces, can never break the call. Tests
            // override the whole argv via `narrator_argv`.
            $argv = config('kraite.guard.narrator_argv') ?? [
                (string) config('kraite.guard.narrator_binary', 'claude'),
                '-p',
                '--model', (string) config('kraite.guard.narrator_model', 'claude-haiku-4-5'),
                '--output-format', 'text',
            ];
            $timeout = (int) config('kraite.guard.narrator_timeout', 120);

            $result = Process::timeout($timeout)->input($prompt)->run($argv);

            if (! $result->successful()) {
                Log::warning('[monitor-narrate] narrator command failed — leaving stub', [
                    'exit' => $result->exitCode(),
                    'err' => mb_substr($result->errorOutput(), 0, 300),
                ]);

                return null;
            }

            $out = trim($result->output());

            return $out === '' ? null : $out;
        } catch (Throwable $e) {
            Log::warning('[monitor-narrate] narrator threw — leaving stub', ['message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Tail of today's Laravel log, ERROR lines only, capped — the raw
     * material the narrator matches against. Defensive: never throws.
     */
    private function recentErrorLog(): string
    {
        try {
            $logFile = storage_path('logs/laravel-'.Carbon::now()->format('Y-m-d').'.log');
            if (! File::exists($logFile)) {
                return '(no log file today)';
            }

            $lines = array_slice(file($logFile) ?: [], -3000);
            $errors = array_filter($lines, static fn ($l) => str_contains($l, '.ERROR'));

            return mb_substr(implode('', array_slice($errors, -60)), -6000);
        } catch (Throwable) {
            return '(log read failed)';
        }
    }
}
