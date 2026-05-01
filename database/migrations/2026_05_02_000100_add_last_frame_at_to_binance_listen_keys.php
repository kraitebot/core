<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Add per-account last_frame_at heartbeat to binance_listen_keys
|--------------------------------------------------------------------------
|
| The user-data daemon already tracks `lastFrameAt` per WS instance in
| memory, but that's invisible outside the process. Persisting the same
| value to a DB column lets the admin UI / external monitors detect
| silent stream death without parsing logs:
|
|   • account 1's WS dies but daemon keeps running
|   • supervisor reports RUNNING (correct — the process IS alive)
|   • last_frame_at on account 1 stops advancing while account 5 keeps
|     ticking → unambiguous "this account's stream is dead" signal.
|
| Throttled to one write per ~30s in the daemon to avoid a write storm
| on heavy frame cadence; DB precision (timestamp(3)) is fine for
| seconds-resolution monitoring.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('binance_listen_keys', function (Blueprint $table): void {
            $table->timestamp('last_frame_at', 3)
                ->nullable()
                ->after('last_keep_alive_at')
                ->comment('Last time the daemon received a WS frame for this account. Updated by the user-data daemon (throttled). Null until the first frame arrives. Used by ops monitoring to detect silent stream death — a stale last_frame_at while the supervisor reports RUNNING means the WS is dead but the process isn\'t.');
        });
    }

    public function down(): void
    {
        Schema::table('binance_listen_keys', function (Blueprint $table): void {
            $table->dropColumn('last_frame_at');
        });
    }
};
