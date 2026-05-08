<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('steps_dispatcher_saturation', function (Blueprint $table) {
            $table->id();
            $table->string('group', 50);
            $table->timestamp('bucket_started_at');
            $table->unsignedInteger('ticks_observed')->default(0);
            $table->unsignedInteger('ticks_capped')->default(0);
            $table->unsignedInteger('ticks_capped_with_leftover')->default(0);
            $table->unsignedInteger('total_dispatched')->default(0);
            $table->unsignedInteger('max_pending_after')->default(0);
            $table->timestamps();

            $table->unique(['group', 'bucket_started_at'], 'idx_sds_group_bucket_unique');
            $table->index('bucket_started_at', 'idx_sds_bucket');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('steps_dispatcher_saturation');
    }
};
