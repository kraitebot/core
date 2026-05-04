<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lifecycle scenarios — the top-level container for a manually-driven
 * position lifecycle walkthrough.
 *
 * Each scenario captures a side (LONG or SHORT — homogeneous in v1),
 * the account whose config (leverage, margin %, etc.) was frozen at
 * creation, and the optional branch lineage.
 *
 * Branching: parent_scenario_id + branched_from_t_index let a scenario
 * declare itself a fork of an existing one. Frames up to and including
 * `branched_from_t_index` were copied at branch time and are then
 * independent — edits to the parent do NOT propagate to the child.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lifecycle_scenarios', function (Blueprint $table): void {
            $table->id();

            $table->string('name', 200);

            // LONG | SHORT — homogeneous across all tokens in the scenario.
            $table->string('side', 10);

            // Account whose leverage / margin% config was frozen into
            // every scenario_token at creation time. Kept as a soft
            // reference (no FK) so deleting an account doesn't cascade
            // through historical scenarios.
            $table->unsignedBigInteger('account_id')->nullable();

            // Branch lineage. NULL parent = root scenario.
            $table->unsignedBigInteger('parent_scenario_id')->nullable();
            $table->unsignedInteger('branched_from_t_index')->nullable();

            // Created-by user id, soft reference (no FK).
            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();

            $table->index('parent_scenario_id');
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lifecycle_scenarios');
    }
};
