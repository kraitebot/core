<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the `recovery_completed` canonical.
 *
 * Fired by `kraite:recover-positions` at the end of a successful
 * (non-dry-run) recovery so the operator gets a Pushover / email /
 * Telegram ping confirming the run completed (vs silent
 * stdout-only confirmation today). Body carries the run summary
 * (positions / orders created / updated / skipped + warnings count
 * + snapshot path).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('notifications')->updateOrInsert(
            ['canonical' => 'recovery_completed'],
            [
                'title' => 'Recovery Completed',
                'description' => 'Emitted at the end of a successful kraite:recover-positions run with the run summary.',
                'usage_reference' => 'Commands/RecoverPositionsCommand',
                'default_severity' => 'info',
                'verified' => 1,
                'cache_duration' => 0,
                'cache_key' => null,
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('notifications')->where('canonical', 'recovery_completed')->delete();
    }
};
