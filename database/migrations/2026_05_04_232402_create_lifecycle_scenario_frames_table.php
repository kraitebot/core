<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A frame is one column in the spreadsheet: T0, T1, T2, ...
 *
 * Frames carry no computed state of their own. The calculator derives
 * each frame's per-token state by replaying every event from T0 up to
 * and including this frame's `t_index`. That keeps editing T1 from
 * silently invalidating T2's stored state — there is no stored state
 * to invalidate.
 *
 * `t_index` is contiguous within a scenario but is allowed to be
 * sparse during reorders (we re-densify on save). Uniqueness is on
 * (scenario_id, t_index).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lifecycle_scenario_frames', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('scenario_id')
                ->constrained('lifecycle_scenarios')
                ->restrictOnDelete();

            $table->unsignedInteger('t_index');

            // Optional human label, e.g. "BTC -3% candle".
            $table->string('label', 200)->nullable();

            $table->timestamps();

            $table->unique(['scenario_id', 't_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lifecycle_scenario_frames');
    }
};
