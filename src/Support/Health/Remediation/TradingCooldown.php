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
     * @param  array<string, mixed>  $evidence
     */
    private function sendPushover(string $reason, array $evidence): void
    {
        try {
            $token = config('kraite.admin_user_pushover_application_key');
            $user = config('kraite.admin_user_pushover_user_key');

            if (! is_string($token) || $token === '' || ! is_string($user) || $user === '') {
                return;
            }

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

            Http::asForm()->timeout(10)->post('https://api.pushover.net/1/messages.json', [
                'token' => $token,
                'user' => $user,
                'priority' => 1,
                'title' => 'KRAITE COOLED — opens halted',
                'message' => "Trigger: {$reason}\n{$summary}\n\nBot stopped opening new positions. Existing positions safe. Incident file written for review.",
            ]);
        } catch (Throwable $exception) {
            Log::error('[monitor-guard] guard pushover failed (cooldown still applied)', [
                'reason' => $reason,
                'message' => $exception->getMessage(),
            ]);
        }
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
