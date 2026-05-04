<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-frame, per-token events. The source of truth for everything
 * the calculator displays.
 *
 * Event types in v1:
 *   - set_price        — operator overrides this token's market price.
 *                        payload: { price: number }
 *   - mark_limit_filled — operator declares limit N filled at limit price.
 *                        payload: { limit_index: int }   (1..N)
 *   - manual_close     — operator force-closes some/all of the open
 *                        position at a given price.
 *                        payload: { qty: number, price: number, reason?: string }
 *   - apply_slippage   — operator declares the next implicit fill
 *                        slipped past intended level by X%.
 *                        payload: { percent: number }
 *
 * The list grows over time. The `event_type` column stays a string
 * (not enum) so adding a new event doesn't require a schema change.
 *
 * Order of evaluation within a single frame: events fire in `id`
 * order, then any auto-derived events (TP / SL hits resulting from
 * the new price level) are applied last.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lifecycle_scenario_frame_events', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('frame_id')
                ->constrained('lifecycle_scenario_frames')
                ->restrictOnDelete();

            $table->foreignId('scenario_token_id')
                ->constrained('lifecycle_scenario_tokens')
                ->restrictOnDelete();

            $table->string('event_type', 32);

            $table->json('event_data');

            $table->timestamps();

            $table->index(['frame_id', 'scenario_token_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lifecycle_scenario_frame_events');
    }
};
