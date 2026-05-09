<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * Provision the `trading_*` step-dispatcher table set as a regular migration
 * step.
 *
 * Background — the trading-prefix split (1.34.0) carved every trade-critical
 * workflow into its own dispatcher ecosystem against the same MySQL DB. The
 * tables (`trading_steps`, `trading_steps_archive`, `trading_steps_dispatcher`,
 * `trading_steps_dispatcher_ticks`) are provisioned in production by running
 * `php artisan steps:install --prefix=trading` once at deploy time. That
 * command is idempotent: existing tables are skipped, missing ones are
 * created, and the dispatcher seed (10 group rows) only fires when the
 * dispatcher table itself is freshly created.
 *
 * Outside of a deploy, two paths legitimately wipe the DB and need the
 * trading set restored before any code that reads `trading_steps` can run:
 *
 *   1. `RefreshDatabase` in the Pest suite (db:wipe + migrate on first test
 *      of every process). OrderObserver, CreatePositionsCommand, and the
 *      WAP / drift / observer tests all query `trading_steps` to dedupe
 *      live workflows; without the table they crash on every observer-
 *      touching feature test.
 *
 *   2. A local dev re-prepping their DB via `migrate:fresh` against
 *      `kraite_tests` (CLAUDE.md's documented test reset path).
 *
 * Routing the same idempotent install through a Laravel migration lets both
 * paths arrive at the same shape as production — without forking a second
 * "test bootstrap" code path that could drift from the deploy command.
 *
 * The migration is intentionally a thin wrapper: all create/seed logic lives
 * in `steps:install` so a future schema change to the prefixed tables
 * propagates from one place rather than two. `down()` is a deliberate no-op
 * — `migrate:rollback` against this should not destroy a live trading
 * dispatcher's table set on a partial rollback. The package's own teardown
 * tooling owns that direction.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Defence-in-depth: even though `steps:install` performs the same
        // probe internally, gating the Artisan call here keeps the
        // migration's intent legible at the call site and avoids a stray
        // command boot when the prefix is already complete.
        if (Schema::hasTable('trading_steps')
            && Schema::hasTable('trading_steps_dispatcher')
            && Schema::hasTable('trading_steps_dispatcher_ticks')
            && Schema::hasTable('trading_steps_archive')) {
            return;
        }

        Artisan::call('steps:install', ['--prefix' => 'trading']);
    }

    public function down(): void
    {
        // Intentional no-op — see class docblock.
    }
};
