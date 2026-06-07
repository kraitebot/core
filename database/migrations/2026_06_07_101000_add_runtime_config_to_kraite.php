<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promote a set of operational ENV/config knobs onto the kraite singleton
 * so they can be flipped live (no .env edit + config:cache + horizon
 * restart). Every column is nullable; a NULL value inherits the existing
 * config() default, so behaviour is unchanged until an operator sets one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kraite', function (Blueprint $table): void {
            $table->boolean('can_trade')
                ->nullable()
                ->after('allow_opening_positions')
                ->comment('Global master trading kill switch (used for BSCS / black-swan suspension). NULL inherits config kraite.can_trade. false = the first gate in HasTradingGuards::canOpenPositions() short-circuits, halting ALL new opens fleet-wide; existing positions untouched.');

            $table->boolean('notifications_enabled')
                ->nullable()
                ->after('can_trade')
                ->comment('Global notifications master switch. NULL inherits config kraite.notifications_enabled. false = no notification is sent, full stop. true = delivery falls through to the per-user users.notifications_enabled toggle, EXCEPT Critical-severity notifications which always reach the account user.');

            $table->string('td_correlation_type', 20)
                ->nullable()
                ->after('notifications_enabled')
                ->comment('Which BTC-correlation series token discovery reads (rolling | pearson | spearman). NULL inherits config kraite.token_discovery.correlation_type.');

            $table->boolean('corr_enabled')
                ->nullable()
                ->after('td_correlation_type')
                ->comment('Whether the BTC-correlation computation pipeline runs. NULL inherits config kraite.correlation.enabled.');

            $table->boolean('elast_enabled')
                ->nullable()
                ->after('corr_enabled')
                ->comment('Whether the BTC-elasticity computation pipeline runs. NULL inherits config kraite.elasticity.enabled.');

            $table->unsignedInteger('trail_retention_hours')
                ->nullable()
                ->after('elast_enabled')
                ->comment('Hours of position-trail history to retain before the purge cron deletes older rows (0 = purge disabled). NULL inherits config kraite.positions.trail_retention_hours.');
        });
    }

    public function down(): void
    {
        Schema::table('kraite', function (Blueprint $table): void {
            $table->dropColumn([
                'can_trade',
                'notifications_enabled',
                'td_correlation_type',
                'corr_enabled',
                'elast_enabled',
                'trail_retention_hours',
            ]);
        });
    }
};
