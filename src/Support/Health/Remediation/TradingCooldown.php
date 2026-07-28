<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Health\Remediation;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Kraite\Core\Models\Kraite;
use Throwable;

final class TradingCooldown
{
    public function isActive(): bool
    {
        $value = Kraite::query()->value('allow_opening_positions');

        return $value !== null && (bool) $value === false;
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    public function enter(string $reason, array $evidence): bool
    {
        $engine = Kraite::query()->first();

        if ($engine === null || (bool) $engine->allow_opening_positions === false) {
            return false;
        }

        $engine->update(['allow_opening_positions' => false]);

        $this->writeIncident($reason, $evidence);
        $this->sendPushover($reason, $evidence);

        return true;
    }

    /**
     * The trigger recorded in the currently open incident, or null when
     * no incident is on file. A latch without an open incident (set by
     * hand, or the file was already archived) reads as null — the
     * auto-release path treats that as strictly manual territory.
     */
    public function activeIncidentTrigger(): ?string
    {
        try {
            $marker = base_path('monitoring/OPEN-INCIDENT');

            if (! File::exists($marker)) {
                return null;
            }

            $incident = base_path('monitoring/'.trim(File::get($marker)));

            if (! File::exists($incident)) {
                return null;
            }

            if (preg_match('/^- trigger: \*\*(.+)\*\*$/m', File::get($incident), $matches) !== 1) {
                return null;
            }

            return $matches[1];
        } catch (Throwable $exception) {
            Log::warning('[monitor-guard] could not read active incident trigger', [
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Re-enable openings after the guard's own latch condition has
     * cleared: flip the switch back on, append the resolution to the
     * incident record, archive the open marker, and page the operator.
     * Counterpart of enter() — a no-op unless the latch is active.
     *
     * @param  array<string, mixed>  $evidence
     */
    public function release(string $reason, array $evidence): bool
    {
        $engine = Kraite::query()->first();

        if ($engine === null || (bool) $engine->allow_opening_positions === true) {
            return false;
        }

        $engine->update(['allow_opening_positions' => true]);

        $this->archiveIncident($reason, $evidence);
        $this->sendResumePushover($reason, $evidence);

        return true;
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function archiveIncident(string $reason, array $evidence): void
    {
        try {
            $marker = base_path('monitoring/OPEN-INCIDENT');

            if (File::exists($marker)) {
                $incident = base_path('monitoring/'.trim(File::get($marker)));

                if (File::exists($incident)) {
                    $resolution = "\n## Resolution\n\n";
                    $resolution .= '- auto-released at '.Carbon::now()->toDateTimeString()." (server time)\n";
                    $resolution .= "- reason: {$reason}\n";
                    $resolution .= '- evidence: '.json_encode($evidence, JSON_UNESCAPED_SLASHES)."\n";

                    File::append($incident, $resolution);
                }

                File::delete($marker);
            }
        } catch (Throwable $exception) {
            Log::error('[monitor-guard] failed to archive incident (opens re-enabled anyway)', [
                'reason' => $reason,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function sendResumePushover(string $reason, array $evidence): void
    {
        try {
            $summary = collect($evidence)
                ->map(function (mixed $value, string $key): string {
                    return is_string($value) || is_int($value) || is_float($value)
                        ? $key.'='.$value
                        : $key;
                })
                ->implode(', ');

            $this->postPushover(
                priority: 0,
                title: 'KRAITE RESUMED — opens re-enabled',
                message: "Cleared: {$reason}\n{$summary}\n\nThe guard condition stayed clean for the full recovery window, so new position openings have resumed automatically. Incident archived.",
            );
        } catch (Throwable $exception) {
            Log::error('[monitor-guard] resume pushover failed (opens re-enabled anyway)', [
                'reason' => $reason,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function sendPushover(string $reason, array $evidence): void
    {
        try {
            $summary = collect($evidence)
                ->map(function (mixed $value, string $key): string {
                    if (is_bool($value)) {
                        return $key.'='.($value ? 'true' : 'false');
                    }

                    return is_string($value) || is_int($value) || is_float($value)
                        ? $key.'='.$value
                        : $key;
                })
                ->implode(', ');

            $this->postPushover(
                priority: 1,
                title: 'KRAITE COOLED — opens halted',
                message: "Trigger: {$reason}\n{$summary}\n\nBot stopped opening new positions. Existing positions safe. Incident file written for review.",
            );
        } catch (Throwable $exception) {
            Log::error('[monitor-guard] guard pushover failed (cooldown still applied)', [
                'reason' => $reason,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Shared Pushover transport for the cooled/resumed alerts. Silently
     * a no-op when the credentials are not configured.
     */
    private function postPushover(int $priority, string $title, string $message): void
    {
        $token = config('kraite.admin_user_pushover_application_key');
        $user = config('kraite.admin_user_pushover_user_key');

        if (! is_string($token) || $token === '' || ! is_string($user) || $user === '') {
            return;
        }

        Http::asForm()->timeout(10)->post('https://api.pushover.net/1/messages.json', [
            'token' => $token,
            'user' => $user,
            'priority' => $priority,
            'title' => $title,
            'message' => $message,
        ]);
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function writeIncident(string $reason, array $evidence): void
    {
        try {
            $directory = base_path('monitoring');
            File::ensureDirectoryExists($directory);

            $now = Carbon::now();
            $stamp = $now->format('Ymd_His');
            $file = $directory.'/'.$stamp.'.md';

            $body = "# Kraite trading incident — {$stamp}\n\n";
            $body .= "- detected_at: {$now->toDateTimeString()} (server time)\n";
            $body .= "- trigger: **{$reason}**\n";
            $body .= "- action_taken: cooled the bot (allow_opening_positions=false)\n";
            $body .= "- narrated: NO  <!-- the Haiku narrator flips this to YES -->\n\n";
            $body .= "## Evidence (deterministic)\n\n```json\n".json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n```\n\n";
            $body .= "## For the operator / terminal LLM\n\n";
            $body .= "The bot has stopped opening new positions. Existing positions keep trading and stay protected.\n";
            $body .= "Diagnose the trigger above, fix the root cause, then clear the cooldown:\n";
            $body .= "`allow_opening_positions=true` on the kraite singleton, and delete `monitoring/OPEN-INCIDENT`.\n\n";
            $body .= "## Narration (Haiku fills this in)\n\n_pending_\n";

            File::put($file, $body);
            File::put($directory.'/OPEN-INCIDENT', $stamp.'.md');
        } catch (Throwable $exception) {
            Log::error('[monitor-guard] failed to write incident file (cooldown still applied)', [
                'reason' => $reason,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
